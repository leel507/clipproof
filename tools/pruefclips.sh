#!/bin/sh
# SPDX-FileCopyrightText: 2026 Schauties Media
# SPDX-License-Identifier: AGPL-3.0-or-later
# Erzeugt Prüfclips mit eingeblendeter Bildnummer (Index ab 0) und
# Timecode, dazu eine Timecode-Spur wie bei Resolve. Erzeugtes Material,
# kein Kundenmaterial. Läuft in einem Wegwerf-Container, auf dem Mac wird
# nichts installiert:
#
#   docker run --rm -v "$PWD/testdaten:/v" -v "$PWD/tools:/t:ro" \
#     php:8.3-cli-alpine sh /t/pruefclips.sh
#
# Sollwerte am letzten Bild (11.10.2026 abgelesen):
#   pruef_2997df  Frame 1798  01:00:59;28
#   pruef_5994df  Frame 3596  01:00:59;56
set -e
apk add -q --no-progress ffmpeg font-dejavu >/dev/null 2>&1
F=/usr/share/fonts/dejavu/DejaVuSansMono-Bold.ttf
mach() { # name rate start_tc
	name=$1; rate=$2; tc=$3; esc=$(echo "$tc" | sed 's/:/\\:/g; s/;/\\;/g')
	ffmpeg -v error -y -f lavfi -i "testsrc2=size=1280x720:rate=$rate:duration=60" \
		-vf "drawbox=x=0:y=230:w=1280:h=260:color=black@0.8:t=fill,drawtext=fontfile=$F:text='Frame %{frame_num}':start_number=0:x=60:y=250:fontsize=96:fontcolor=white,drawtext=fontfile=$F:timecode='$esc':rate=$rate:x=60:y=370:fontsize=96:fontcolor=yellow" \
		-c:v libx264 -preset medium -profile:v high -pix_fmt yuv420p -b:v 3M -maxrate 4M -bufsize 8M -g 25 \
		-r "$rate" -fps_mode cfr -timecode "$tc" -movflags +faststart "/v/$name.mp4"
	echo "fertig: $name.mp4"
}
mach pruef_25 25 "00:00:00:00"
mach pruef_2997df 30000/1001 "01:00:00;00"
mach pruef_5994df 60000/1001 "01:00:00;00"
