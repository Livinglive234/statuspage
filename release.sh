#!/usr/bin/env bash
# Build a release zip and update update.json.
# Usage: ./release.sh 1.0.1
set -euo pipefail

VERSION="${1:?usage: ./release.sh <version>}"
REPO="Livinglive234/statuspage"
cd "$(dirname "$0")"

# 1. bump the version in plugin.json
python3 - "$VERSION" <<'PY'
import re, sys
p = "plugin.json"
s = open(p).read()
s, n = re.subn(r'("version"\s*:\s*")[^"]*(")', r'\g<1>' + sys.argv[1] + r'\g<2>', s, count=1)
assert n == 1, "version field not found in plugin.json"
open(p, "w").write(s)
PY

# 2. build dist/statuspage.zip with a top-level "statuspage/" folder
rm -rf dist && mkdir -p dist/build/statuspage
git ls-files -co --exclude-standard | grep -vE '^(dist/|release\.sh$|update\.json$|\.gitignore$|.*\.zip$)' | while read -r f; do
  mkdir -p "dist/build/statuspage/$(dirname "$f")"
  cp "$f" "dist/build/statuspage/$f"
done
(cd dist/build && zip -qr ../statuspage.zip statuspage)
rm -rf dist/build

# 3. checksum + update.json
if command -v sha256sum >/dev/null; then SHA=$(sha256sum dist/statuspage.zip | cut -d' ' -f1); else SHA=$(shasum -a 256 dist/statuspage.zip | cut -d' ' -f1); fi
python3 - "$VERSION" "$SHA" "$REPO" <<'PY'
import json, sys
version, sha, repo = sys.argv[1:4]
data = {"*": {
    "version": version,
    "download_url": f"https://github.com/{repo}/releases/download/v{version}/statuspage.zip",
    "sha256": sha,
}}
open("update.json", "w").write(json.dumps(data, indent=2) + "\n")
PY

cat <<MSG

Built dist/statuspage.zip  (sha256 $SHA)

Next:
  git add -A && git commit -m "Release v$VERSION"
  git tag v$VERSION && git push && git push --tags
  Create a GitHub release for tag v$VERSION and upload dist/statuspage.zip to it:

    In the browser:  repo > Releases > Draft a new release > choose tag v$VERSION >
                     attach dist/statuspage.zip > Publish release
    Or with the GitHub CLI:
                     gh release create v$VERSION dist/statuspage.zip --title "v$VERSION" --notes "Release v$VERSION"

  (Upload exactly this zip, or the checksum in update.json will not match.)
MSG
