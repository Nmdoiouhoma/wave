#!/bin/sh
# Generates short synthetic tones for MSW mocks (never copyrighted audio).
set -eu
OUT="$(dirname "$0")/../public/mock-audio"
mkdir -p "$OUT"
gen() { # name freq seconds
  ffmpeg -hide_banner -loglevel error -y \
    -f lavfi -i "sine=frequency=$2:duration=$3:sample_rate=44100" \
    -f lavfi -i "sine=frequency=$(($2 * 3 / 2)):duration=$3:sample_rate=44100" \
    -filter_complex "[0][1]amix=inputs=2,volume=0.5,tremolo=f=2:d=0.4,afade=t=in:d=1,afade=t=out:st=$(($3 - 2)):d=2" \
    -ac 1 -c:a aac -b:a 48k -movflags +faststart "$OUT/$1.m4a"
}
gen tone-a 220 48
gen tone-b 262 36
gen tone-c 330 60
