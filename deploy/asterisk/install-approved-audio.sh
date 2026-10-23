#!/bin/sh
set -eu
# Execute on the PBX only, with an approved UUID, reviewed checksum and canonical WAV.
if [ "$#" -ne 3 ]; then echo 'Usage: install-approved-audio.sh UUID SHA256 WAV' >&2; exit 1; fi
asset_uuid=$1
asset_checksum=$2
asset_source=$3
case "$asset_uuid" in *[!0-9a-f-]*|'') echo 'Invalid UUID' >&2; exit 1;; esac
[ "${#asset_uuid}" -eq 36 ] || exit 1
case "$asset_checksum" in *[!0-9a-f]*|'') echo 'Invalid checksum' >&2; exit 1;; esac
[ "${#asset_checksum}" -eq 64 ] || exit 1
[ -f "$asset_source" ] || exit 1
actual_checksum=$(sha256sum -- "$asset_source")
actual_checksum=${actual_checksum%% *}
[ "$actual_checksum" = "$asset_checksum" ] || { echo 'Checksum mismatch' >&2; exit 1; }
asset_directory=${PBX_SOUNDS_DIR:-/var/lib/asterisk/sounds}/gwosevo
install -d -o asterisk -g asterisk -m 0750 "$asset_directory"
install -o asterisk -g asterisk -m 0640 "$asset_source" "$asset_directory/.$asset_uuid.tmp"
mv -- "$asset_directory/.$asset_uuid.tmp" "$asset_directory/$asset_uuid.wav"
asterisk -rx "database put vb-audio $asset_uuid $asset_checksum"
printf 'Verify PBX checksum and record deployment evidence for %s\n' "$asset_uuid"
sha256sum -- "$asset_directory/$asset_uuid.wav"
