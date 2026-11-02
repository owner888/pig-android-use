// Text on an image, read by Apple's Vision framework. macOS only; nothing to install.
//
//     osascript -l JavaScript vision-ocr.js /path/to/screenshot.jpg
//
// Prints a JSON array, one entry per line of text: {text, confidence, x, y, w, h}, where the
// box is a fraction of the image with the origin at the bottom left (Vision's own convention;
// VisionOcr::toLines() turns it into pixels from the top left).

ObjC.import('Foundation');
ObjC.import('Vision');

function run(argv) {
    if (argv.length < 1) {
        throw new Error('usage: osascript -l JavaScript vision-ocr.js <image>');
    }

    const url = $.NSURL.fileURLWithPath(argv[0]);
    const handler = $.VNImageRequestHandler.alloc.initWithURLOptions(url, $.NSDictionary.dictionary);
    const request = $.VNRecognizeTextRequest.alloc.init;

    request.recognitionLevel = 0; // VNRequestTextRecognitionLevelAccurate
    request.usesLanguageCorrection = true;
    request.recognitionLanguages = $(['zh-Hans', 'zh-Hant', 'en-US']);

    const error = Ref();
    if (!handler.performRequestsError($([request]), error)) {
        const reason = error[0] ? ObjC.unwrap(error[0].localizedDescription) : 'unknown error';
        throw new Error('Vision could not read ' + argv[0] + ': ' + reason);
    }

    const results = request.results;
    const lines = [];

    for (let i = 0; i < results.count; i++) {
        const observation = results.objectAtIndex(i);
        const candidates = observation.topCandidates(1);

        if (candidates.count === 0) {
            continue;
        }

        const candidate = candidates.objectAtIndex(0);
        const box = observation.boundingBox;

        lines.push({
            text: ObjC.unwrap(candidate.string),
            confidence: candidate.confidence,
            x: box.origin.x,
            y: box.origin.y,
            w: box.size.width,
            h: box.size.height,
        });
    }

    return JSON.stringify(lines);
}
