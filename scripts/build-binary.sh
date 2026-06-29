#!/usr/bin/env bash
#
# Build a self-contained, statically-linked `llmor` binary using static-php-cli.
#
# It compiles a "micro" PHP SAPI with the extensions the CLI needs, then fuses
# it with the application phar into a single executable that runs with no PHP
# installed on the host.
#
# Usage:
#   bash scripts/build-binary.sh
#
# Environment overrides:
#   PHP_VERSION   PHP version to embed            (default: 8.3)
#   EXTENSIONS    comma-separated extension list  (default: see below)
#   SPC           path to the `spc` binary        (default: auto-detect/download)
#
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PHP_VERSION="${PHP_VERSION:-8.3}"
EXTENSIONS="${EXTENSIONS:-curl,openssl,mbstring,phar,filter,tokenizer,sodium,ctype,iconv,posix,pcntl}"
BUILD_DIR="$ROOT_DIR/build"
PHAR="$BUILD_DIR/llmor.phar"
OUTPUT="$BUILD_DIR/llmor"

mkdir -p "$BUILD_DIR"

# ---------------------------------------------------------------------------
# Locate (or fetch) the static-php-cli `spc` binary.
# ---------------------------------------------------------------------------
resolve_spc() {
    if [[ -n "${SPC:-}" ]]; then echo "$SPC"; return; fi
    if command -v spc >/dev/null 2>&1; then echo "spc"; return; fi
    if [[ -x "$ROOT_DIR/.spc/spc" ]]; then echo "$ROOT_DIR/.spc/spc"; return; fi

    echo "==> Downloading static-php-cli (spc)..." >&2
    local os arch asset
    os="$(uname -s)"; arch="$(uname -m)"
    case "$os" in
        Darwin) os="macos" ;;
        Linux)  os="linux" ;;
        *) echo "Unsupported OS: $os. Install spc manually: https://static-php.dev" >&2; exit 1 ;;
    esac
    case "$arch" in
        x86_64|amd64) arch="x86_64" ;;
        arm64|aarch64) arch="aarch64" ;;
        *) echo "Unsupported arch: $arch" >&2; exit 1 ;;
    esac
    asset="spc-${os}-${arch}"
    mkdir -p "$ROOT_DIR/.spc"
    curl -fsSL "https://dl.static-php.dev/static-php-cli/spc-bin/nightly/${asset}.tar.gz" \
        | tar -xz -C "$ROOT_DIR/.spc"
    chmod +x "$ROOT_DIR/.spc/spc"
    echo "$ROOT_DIR/.spc/spc"
}

SPC_BIN="$(resolve_spc)"
echo "==> Using spc: $SPC_BIN"

# ---------------------------------------------------------------------------
# 1. Build the application phar. Box excludes dev dependencies automatically,
#    so we keep dev deps installed (Box itself is one of them).
# ---------------------------------------------------------------------------
echo "==> Building phar..."
composer install --optimize-autoloader
php -d phar.readonly=0 vendor/bin/box compile

# ---------------------------------------------------------------------------
# 2. Build the micro SAPI and fuse it with the phar.
# ---------------------------------------------------------------------------
echo "==> Checking build environment..."
"$SPC_BIN" doctor --auto-fix

echo "==> Downloading PHP $PHP_VERSION sources + extensions ($EXTENSIONS)..."
"$SPC_BIN" download --with-php="$PHP_VERSION" --for-extensions="$EXTENSIONS" --prefer-pre-built

echo "==> Compiling micro SAPI..."
"$SPC_BIN" build "$EXTENSIONS" --build-micro

echo "==> Fusing binary..."
"$SPC_BIN" micro:combine "$PHAR" --output="$OUTPUT"
chmod +x "$OUTPUT"

echo ""
echo "==> Done. Self-contained binary at: $OUTPUT"
"$OUTPUT" --version || true
