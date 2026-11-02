#!/bin/sh
# Build helper/pig-android-helper.dex from helper/src with the Android SDK: javac against the
# newest platform's android.jar, then d8. The .dex is committed, so installing the package needs
# no SDK; rebuild it after changing anything under helper/src, and bump Main.VERSION when the
# protocol changes.
#
#     ANDROID_HOME=~/Library/Android/sdk helper/build.sh
set -eu

cd "$(dirname "$0")"

sdk="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-$HOME/Library/Android/sdk}}"
platform="$(ls -d "$sdk"/platforms/android-* 2>/dev/null | sort -V | tail -1)"
build_tools="$(ls -d "$sdk"/build-tools/* 2>/dev/null | sort -V | tail -1)"

if [ -z "$platform" ] || [ -z "$build_tools" ]; then
    echo "No Android SDK platform or build-tools under $sdk; set ANDROID_HOME." >&2
    exit 1
fi

rm -rf build
mkdir -p build/classes

javac -source 11 -target 11 -Xlint:-options \
    -cp "$platform/android.jar" \
    -d build/classes \
    $(find src -name '*.java')

"$build_tools/d8" --min-api 31 --lib "$platform/android.jar" --output build $(find build/classes -name '*.class')

mv build/classes.dex pig-android-helper.dex
rm -rf build
echo "built $(pwd)/pig-android-helper.dex ($(wc -c < pig-android-helper.dex) bytes) with $(basename "$platform"), build-tools $(basename "$build_tools")"
