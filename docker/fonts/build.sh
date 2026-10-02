#!/usr/bin/env bash
# Builds the UI's font: the text cuts of the Inter shipped by the image's fonts-inter (Regular 400, Medium 500, SemiBold 600),
# subset to the scripts the board needs; the layout features stay at the subsetter's defaults (kerning, ligatures, contextual
# alternates) plus tabular figures and case-sensitive forms. The files are served immutable under their names, so a changed file needs a new
# revision number in its name: kanban.css (@font-face), Ui::ASSETS and app.blade.php (preload).
set -euo pipefail
cd "$(dirname "$0")/../.."

revision=2
dir=/usr/share/fonts/opentype/inter
dpkg-query -W fonts-inter >/dev/null
# Latin, Latin-1, Extended-A (Serbian č ć š ž đ), Cyrillic basic, punctuation, arrows, minus, euro, trademark
unicodes='U+0000-017F,U+0400-045F,U+2000-206F,U+2190-2193,U+2212,U+20AC,U+2122,U+FEFF,U+FFFD'

total=0
for cut in Regular:400 Medium:500 SemiBold:600; do
    name=${cut%%:*}
    weight=${cut##*:}
    out="resources/dist/inter-${revision}-${weight}.woff2"
    python3 -m fontTools.subset "$dir/Inter-${name}.otf" --unicodes="$unicodes" --layout-features+=tnum,case --no-hinting --desubroutinize --flavor=woff2 --output-file="$out"
    size=$(stat -c %s "$out")
    total=$((total + size))
    echo "$out: $size bytes"
done
cp /usr/share/doc/fonts-inter/copyright resources/dist/inter-LICENSE.txt
echo "total $total bytes ($(dpkg-query -W -f='${Version}' fonts-inter))"
[ "$total" -le 122880 ] || { echo "over the 120 KB budget: drop the Cyrillic range" >&2; exit 1; }
