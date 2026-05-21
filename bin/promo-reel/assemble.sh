#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
# SPDX-FileCopyrightText: 2025-2026 Marcus Quinn
#
# Superdav AI Agent — assemble the 60-second promo reel.
#
# Reads bin/promo-reel/prompts.json, takes each clip in output/clips/, and
# stitches the final reel together with title cards generated on the fly by
# ffmpeg. Title beats (kind=title) are rendered as drawtext over a black
# background. Prompt beats (kind=prompt) are time-stretched from their
# native recording length to the beat's duration_seconds.
#
# Usage:
#   bash bin/promo-reel/assemble.sh                         # default reel
#   bash bin/promo-reel/assemble.sh --music path/to/beat.mp3  # with music bed
#
# Outputs:
#   bin/promo-reel/output/superdav-ai-agent-reel.mp4
#
# Dependencies: ffmpeg, jq. Both checked at startup.

set -euo pipefail

REEL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROMPTS_JSON="${REEL_DIR}/prompts.json"
OUTPUT_DIR="${REEL_DIR}/output"
CLIPS_DIR="${OUTPUT_DIR}/clips"
SEGMENTS_DIR="${OUTPUT_DIR}/segments"
FINAL_OUT="${OUTPUT_DIR}/superdav-ai-agent-reel.mp4"

mkdir -p "${SEGMENTS_DIR}"

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

music_path=""
while [[ $# -gt 0 ]]; do
	case "$1" in
		--music)
			music_path="$2"
			shift 2
			;;
		*)
			echo "[promo-reel] unknown arg: $1" >&2
			exit 2
			;;
	esac
done

# ---------------------------------------------------------------------------
# Dependency check
# ---------------------------------------------------------------------------

require() {
	local cmd="$1"
	if ! command -v "${cmd}" >/dev/null 2>&1; then
		echo "[promo-reel] missing dependency: ${cmd}" >&2
		return 1
	fi
	return 0
}

require ffmpeg || exit 2
require jq     || exit 2

if [[ ! -f "${PROMPTS_JSON}" ]]; then
	echo "[promo-reel] missing ${PROMPTS_JSON}" >&2
	exit 2
fi

# ---------------------------------------------------------------------------
# Read global output config
# ---------------------------------------------------------------------------

out_w="$(jq -r '.output.width'  "${PROMPTS_JSON}")"
out_h="$(jq -r '.output.height' "${PROMPTS_JSON}")"
fps="$(  jq -r '.output.fps'    "${PROMPTS_JSON}")"

echo "[promo-reel] target ${out_w}x${out_h} @ ${fps}fps"

# Find a suitable font for drawtext. drawtext fails hard if the font isn't
# resolvable, so we probe common Linux paths and fall back to a font file
# in the system. The user can override via PROMO_FONT.
font_path="${PROMO_FONT:-}"
if [[ -z "${font_path}" ]]; then
	for candidate in \
		/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf \
		/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf \
		/usr/share/fonts/truetype/freefont/FreeSansBold.ttf \
		/System/Library/Fonts/Helvetica.ttc
	do
		if [[ -f "${candidate}" ]]; then
			font_path="${candidate}"
			break
		fi
	done
fi
if [[ -z "${font_path}" || ! -f "${font_path}" ]]; then
	echo "[promo-reel] no usable font found (set PROMO_FONT=/path/to.ttf)" >&2
	exit 2
fi
echo "[promo-reel] font: ${font_path}"

# ---------------------------------------------------------------------------
# Per-beat segment builders
# ---------------------------------------------------------------------------

# Escape a string for safe inclusion in drawtext's text= option. Special
# characters that ffmpeg parses: ':', '\', '%', and the wrapping quote.
escape_drawtext() {
	local raw="$1"
	# Order matters: backslash first.
	raw="${raw//\\/\\\\}"
	raw="${raw//:/\\:}"
	raw="${raw//\'/\\\'}"
	printf '%s' "${raw}"
}

# Render a title-card segment as a self-contained MP4 with two stacked
# lines (headline + subhead) and a small footer at the bottom.
render_title() {
	local beat_id="$1"
	local duration="$2"
	local headline="$3"
	local subhead="$4"
	local footer="$5"
	local out_path="${SEGMENTS_DIR}/${beat_id}.mp4"

	local hl_e
	hl_e="$(escape_drawtext "${headline}")"
	local sh_e
	sh_e="$(escape_drawtext "${subhead}")"
	local ft_e
	ft_e="$(escape_drawtext "${footer}")"

	# Status to stderr so the function's stdout contains *only* the segment
	# path that the caller will capture via $(render_title ...).
	echo "[promo-reel] title  ${beat_id} (${duration}s)" >&2

	# drawtext y coordinates: headline above centre, subhead below, footer near bottom.
	# Fade in (0–0.4 s) + fade out (last 0.4 s) using fade filter chained after drawtext.
	local fade_out_start
	fade_out_start="$(awk -v d="${duration}" 'BEGIN{printf "%.2f", d - 0.4}')"

	ffmpeg -y -loglevel error \
		-f lavfi -i "color=c=black:s=${out_w}x${out_h}:r=${fps}:d=${duration}" \
		-vf "\
drawtext=fontfile='${font_path}':text='${hl_e}':fontcolor=white:fontsize=86:x=(w-text_w)/2:y=(h/2)-120,\
drawtext=fontfile='${font_path}':text='${sh_e}':fontcolor=#bdbdbd:fontsize=60:x=(w-text_w)/2:y=(h/2),\
drawtext=fontfile='${font_path}':text='${ft_e}':fontcolor=#7a7a7a:fontsize=38:x=(w-text_w)/2:y=h-220,\
fade=t=in:st=0:d=0.4,fade=t=out:st=${fade_out_start}:d=0.4" \
		-c:v libx264 -pix_fmt yuv420p -profile:v high -level 4.0 \
		-r "${fps}" -t "${duration}" \
		"${out_path}"

	echo "${out_path}"
}

# Render a prompt-clip segment: take the recorded .webm, time-stretch it to
# fit the beat's target duration, overlay a caption pill at the top, fade
# in/out, and re-encode to MP4 so concat is seamless.
render_prompt() {
	local beat_id="$1"
	local duration="$2"
	local caption="$3"
	local src_clip="${CLIPS_DIR}/${beat_id}.webm"
	local out_path="${SEGMENTS_DIR}/${beat_id}.mp4"

	if [[ ! -f "${src_clip}" ]]; then
		echo "[promo-reel] missing clip: ${src_clip} — skipping ${beat_id}" >&2
		# Stdout is captured by the caller; emit empty string so the
		# segment list skips this entry cleanly.
		printf ''
		return 0
	fi

	# Status to stderr so the function's stdout contains *only* the segment path.
	echo "[promo-reel] prompt ${beat_id} (${duration}s) ← $(basename "${src_clip}")" >&2

	# Compute the speed factor: native_duration / target_duration.
	# ffprobe gives the source duration; awk produces a setpts multiplier.
	local src_dur
	src_dur="$(ffprobe -v error -show_entries format=duration \
		-of default=noprint_wrappers=1:nokey=1 "${src_clip}")"
	# Default to 1.0 if probe failed.
	if [[ -z "${src_dur}" ]]; then
		src_dur="${duration}"
	fi

	local pts
	pts="$(awk -v d="${duration}" -v s="${src_dur}" 'BEGIN { if (s == 0) print 1.0; else printf "%.6f", d/s }')"

	local cap_e
	cap_e="$(escape_drawtext "${caption}")"
	local fade_out_start
	fade_out_start="$(awk -v d="${duration}" 'BEGIN{printf "%.2f", d - 0.3}')"

	# Filter graph:
	#   1. setpts ${pts}*PTS         — time-stretch (slowdown if pts>1, speed-up if pts<1)
	#   2. scale + pad               — fit any source aspect into the 9:16 canvas
	#   3. drawbox + drawtext        — caption pill near the top
	#   4. fade in + fade out        — soften the cuts
	ffmpeg -y -loglevel error \
		-i "${src_clip}" \
		-vf "\
setpts=${pts}*PTS,\
scale=${out_w}:${out_h}:force_original_aspect_ratio=decrease,\
pad=${out_w}:${out_h}:(ow-iw)/2:(oh-ih)/2:color=black,\
drawbox=x=80:y=80:w=iw-160:h=120:color=black@0.55:t=fill,\
drawtext=fontfile='${font_path}':text='${cap_e}':fontcolor=white:fontsize=52:x=(w-text_w)/2:y=110,\
fade=t=in:st=0:d=0.3,fade=t=out:st=${fade_out_start}:d=0.3" \
		-an \
		-c:v libx264 -pix_fmt yuv420p -profile:v high -level 4.0 \
		-r "${fps}" -t "${duration}" \
		"${out_path}"

	echo "${out_path}"
}

# ---------------------------------------------------------------------------
# Build every segment
# ---------------------------------------------------------------------------

segments_list="${OUTPUT_DIR}/segments.txt"
: >"${segments_list}"

beat_count="$(jq '.beats | length' "${PROMPTS_JSON}")"

for (( i = 0; i < beat_count; i += 1 )); do
	beat="$(jq ".beats[${i}]" "${PROMPTS_JSON}")"
	kind="$(    echo "${beat}" | jq -r '.kind')"
	beat_id="$( echo "${beat}" | jq -r '.id')"
	duration="$(echo "${beat}" | jq -r '.duration_seconds')"

	segment_path=""
	if [[ "${kind}" == "title" ]]; then
		headline="$(echo "${beat}" | jq -r '.headline // ""')"
		subhead="$( echo "${beat}" | jq -r '.subhead  // ""')"
		footer="$(  echo "${beat}" | jq -r '.footer   // ""')"
		segment_path="$(render_title "${beat_id}" "${duration}" "${headline}" "${subhead}" "${footer}")"
	elif [[ "${kind}" == "prompt" ]]; then
		caption="$(echo "${beat}" | jq -r '.caption // ""')"
		segment_path="$(render_prompt "${beat_id}" "${duration}" "${caption}")"
	else
		echo "[promo-reel] skipping unknown kind=${kind} for ${beat_id}" >&2
		continue
	fi

	if [[ -n "${segment_path}" && -f "${segment_path}" ]]; then
		printf "file '%s'\n" "${segment_path}" >>"${segments_list}"
	fi
done

if [[ ! -s "${segments_list}" ]]; then
	echo "[promo-reel] no segments produced — did you run record.js first?" >&2
	exit 1
fi

# ---------------------------------------------------------------------------
# Concat into the final reel
# ---------------------------------------------------------------------------

echo "[promo-reel] concat → ${FINAL_OUT}"

if [[ -n "${music_path}" ]]; then
	if [[ ! -f "${music_path}" ]]; then
		echo "[promo-reel] music file not found: ${music_path}" >&2
		exit 2
	fi
	# Concat video then overlay music, trimmed to the video length.
	tmp_video="${OUTPUT_DIR}/_tmp-video-only.mp4"
	ffmpeg -y -loglevel error -f concat -safe 0 -i "${segments_list}" \
		-c copy "${tmp_video}"
	ffmpeg -y -loglevel error \
		-i "${tmp_video}" -i "${music_path}" \
		-map 0:v:0 -map 1:a:0 -shortest \
		-c:v copy -c:a aac -b:a 192k \
		"${FINAL_OUT}"
	rm -f "${tmp_video}"
else
	# Concat without audio. Add a silent AAC track so platforms that require
	# audio (Reels, Shorts) accept the upload directly.
	tmp_video="${OUTPUT_DIR}/_tmp-video-only.mp4"
	ffmpeg -y -loglevel error -f concat -safe 0 -i "${segments_list}" \
		-c copy "${tmp_video}"
	total_dur="$(ffprobe -v error -show_entries format=duration \
		-of default=noprint_wrappers=1:nokey=1 "${tmp_video}")"
	ffmpeg -y -loglevel error \
		-i "${tmp_video}" \
		-f lavfi -i "anullsrc=r=48000:cl=stereo" \
		-map 0:v:0 -map 1:a:0 -shortest -t "${total_dur}" \
		-c:v copy -c:a aac -b:a 96k \
		"${FINAL_OUT}"
	rm -f "${tmp_video}"
fi

echo "[promo-reel] ✓ ${FINAL_OUT}"
ls -lh "${FINAL_OUT}" | awk '{print "[promo-reel] size: " $5}'
