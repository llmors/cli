<?php

declare(strict_types=1);

namespace Llmor\Cli\Command\SelfUpdate;

use Llmor\Cli\Command\AbstractCommand;
use Llmor\Cli\Console\OutputStyle;
use Phar;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Updates the running phar/binary in place by downloading the latest published
 * release from GitHub.
 *
 * This talks to GitHub directly (not the llmor API), so it takes the raw HTTP
 * client rather than the signing {@see \Llmor\Cli\Client\LlmorClient}. The
 * current version and on-disk binary path are injected so the behaviour is
 * fully unit-testable.
 */
#[AsCommand(
    name: 'self-update',
    description: 'Update llmor to the latest released version.',
)]
final class SelfUpdateCommand extends AbstractCommand
{
    private const string GITHUB_REPO = 'llmors/cli';
    private const string ASSET_NAME = 'llmor.phar';
    private const string LATEST_RELEASE_URL = 'https://api.github.com/repos/'.self::GITHUB_REPO.'/releases/latest';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $currentVersion,
        private readonly ?string $binaryPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('check', null, InputOption::VALUE_NONE, 'Only report whether an update is available; change nothing.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Reinstall the latest release even if already up to date.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the result as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new OutputStyle($input, $output);
        $json = (bool) $input->getOption('json');

        // self-update only makes sense for the packaged phar/binary. From a
        // source checkout Phar::running() is empty, so guide the user instead.
        if (null === $this->binaryPath || '' === $this->binaryPath) {
            $message = 'self-update only works on the phar build. From a source checkout, update with "git pull && composer install".';
            if ($json) {
                $output->writeln($this->encodeJson(['updated' => false, 'reason' => 'not-a-phar', 'from' => $this->currentVersion]));

                return Command::SUCCESS;
            }
            $io->note($message);

            return Command::SUCCESS;
        }

        try {
            $release = $this->fetchLatestRelease();
        } catch (RuntimeException|HttpExceptionInterface $e) {
            if ($json) {
                $output->writeln($this->encodeJson(['updated' => false, 'error' => $e->getMessage()]));

                return Command::FAILURE;
            }
            $io->error('Could not check for updates: '.$e->getMessage());

            return Command::FAILURE;
        }

        $latest = \ltrim($release['tag'], 'v');
        $upToDate = \version_compare($latest, $this->currentVersion, '<=');
        $force = (bool) $input->getOption('force');

        if ($input->getOption('check')) {
            if ($json) {
                $output->writeln($this->encodeJson([
                    'updated' => false,
                    'available' => !$upToDate,
                    'from' => $this->currentVersion,
                    'to' => $latest,
                ]));

                return Command::SUCCESS;
            }

            if ($upToDate) {
                $io->success(\sprintf('llmor is up to date (v%s).', $this->currentVersion));
            } else {
                $io->note(\sprintf('An update is available: v%s → v%s. Run "llmor self-update" to install it.', $this->currentVersion, $latest));
            }

            return Command::SUCCESS;
        }

        if ($upToDate && !$force) {
            if ($json) {
                $output->writeln($this->encodeJson(['updated' => false, 'from' => $this->currentVersion, 'to' => $latest]));

                return Command::SUCCESS;
            }
            $io->success(\sprintf('llmor is already up to date (v%s).', $this->currentVersion));

            return Command::SUCCESS;
        }

        $target = $this->binaryPath;

        try {
            // Download + verify into a temp file beside the target. The target's
            // writability is checked here too, so the success message we print
            // before the in-place swap is trustworthy.
            $staged = $this->stage($release['url'], $release['sha256'], $target);
        } catch (RuntimeException|HttpExceptionInterface $e) {
            if ($json) {
                $output->writeln($this->encodeJson(['updated' => false, 'error' => $e->getMessage()]));

                return Command::FAILURE;
            }
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Announce success BEFORE swapping: replacing the phar that this very
        // process is running from corrupts any class it lazy-loads afterwards.
        if ($json) {
            $output->writeln($this->encodeJson(['updated' => true, 'from' => $this->currentVersion, 'to' => $latest]));
        } else {
            $io->success(\sprintf('Updated llmor v%s → v%s.', $this->currentVersion, $latest));
        }

        if (!@\rename($staged, $target)) {
            @\unlink($staged);
            $io->error(\sprintf('Could not replace %s — re-run with "sudo llmor self-update".', $target));

            return Command::FAILURE;
        }

        // When we really are the phar we just replaced, continuing to execute
        // (Symfony's terminate/shutdown) would read the swapped file at stale
        // offsets and crash. Bail out cleanly. No-op from a source checkout/test.
        if ('' !== Phar::running(false)) {
            exit(Command::SUCCESS);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{tag: string, url: string, sha256: ?string}
     */
    private function fetchLatestRelease(): array
    {
        $response = $this->http->request('GET', self::LATEST_RELEASE_URL, [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'llmor-cli',
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(\sprintf('GitHub returned HTTP %d.', $response->getStatusCode()));
        }

        /** @var array<string, mixed> $body */
        $body = $response->toArray(false);

        $tag = \is_string($body['tag_name'] ?? null) ? $body['tag_name'] : null;
        if (null === $tag) {
            throw new RuntimeException('The latest release has no tag name.');
        }

        $assets = \is_array($body['assets'] ?? null) ? $body['assets'] : [];
        foreach ($assets as $asset) {
            if (!\is_array($asset) || ($asset['name'] ?? null) !== self::ASSET_NAME) {
                continue;
            }

            $url = \is_string($asset['browser_download_url'] ?? null) ? $asset['browser_download_url'] : null;
            if (null === $url) {
                break;
            }

            $sha256 = null;
            if (\is_string($asset['digest'] ?? null) && \str_starts_with($asset['digest'], 'sha256:')) {
                $sha256 = \substr($asset['digest'], \strlen('sha256:'));
            }

            return ['tag' => $tag, 'url' => $url, 'sha256' => $sha256];
        }

        throw new RuntimeException(\sprintf('The latest release has no "%s" asset.', self::ASSET_NAME));
    }

    /**
     * Download the asset beside the target, verify its checksum, confirm it is a
     * loadable phar, and make sure the target is writable. Returns the staged
     * temp-file path, ready to be renamed over the target.
     */
    private function stage(string $url, ?string $expectedSha256, string $target): string
    {
        if (!\is_writable($target) || !\is_writable(\dirname($target))) {
            throw new RuntimeException(\sprintf('%s is not writable — re-run with "sudo llmor self-update".', $target));
        }

        $tmp = \dirname($target).'/.llmor-update-'.\getmypid().'.phar';

        $response = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => 'llmor-cli'],
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(\sprintf('Download failed with HTTP %d.', $response->getStatusCode()));
        }

        if (false === \file_put_contents($tmp, $response->getContent())) {
            throw new RuntimeException(\sprintf('Could not write the download to %s.', $tmp));
        }

        try {
            if (null !== $expectedSha256 && !\hash_equals($expectedSha256, (string) \hash_file('sha256', $tmp))) {
                throw new RuntimeException('Checksum verification failed; the download was discarded.');
            }

            $this->assertValidPhar($tmp);

            $perms = \fileperms($target);
            if (false !== $perms) {
                @\chmod($tmp, $perms & 0o777);
            }
        } catch (Throwable $e) {
            @\unlink($tmp);

            throw $e;
        }

        return $tmp;
    }

    /**
     * Make sure the download is a loadable phar before swapping it in. Skipped
     * when the Phar extension is unavailable — the checksum is the primary gate.
     */
    private function assertValidPhar(string $path): void
    {
        if (!\class_exists(Phar::class)) {
            return;
        }

        try {
            new Phar($path);
        } catch (Throwable $e) {
            throw new RuntimeException('The downloaded file is not a valid llmor phar.', 0, $e);
        }
    }
}
