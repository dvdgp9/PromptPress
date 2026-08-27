'use strict';

const assert = require('node:assert/strict');
const Rich = require('../admin/assets/js/assistant-rich-composer.js');

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed += 1;
        process.stdout.write('PASS ' + name + '\n');
    } catch (error) {
        process.stderr.write('FAIL ' + name + '\n  -> ' + error.message + '\n');
        process.exitCode = 1;
    }
}

test('safe_links_allow_only_http_and_https', () => {
    assert.equal(Rich.safeHref('https://example.com/a?b=1'), 'https://example.com/a?b=1');
    assert.equal(Rich.safeHref('http://example.com'), 'http://example.com/');
    assert.equal(Rich.safeHref('javascript:alert(1)'), '');
    assert.equal(Rich.safeHref('data:text/html;base64,WA=='), '');
    assert.equal(Rich.safeHref('/relative'), '');
});

test('image_sources_are_classified_without_fetching', () => {
    assert.equal(Rich.imageSourceKind('data:image/png;base64,aGVsbG8='), 'data');
    assert.equal(Rich.imageSourceKind('https://cdn.example.com/image.png'), 'remote_url');
    assert.equal(Rich.imageSourceKind('blob:https://mail.example/id'), 'unresolved');
    assert.equal(Rich.imageSourceKind('file:///private/image.png'), 'unresolved');
    assert.equal(Rich.imageSourceKind('javascript:alert(1)'), 'unresolved');
});

test('valid_data_image_is_measured_before_capture', () => {
    const parsed = Rich.parseDataImage('data:image/png;base64,aGVsbG8=', 10);
    assert.deepEqual(parsed, { mime: 'image/png', bytes: 5, base64: 'aGVsbG8=' });
    assert.equal(Rich.parseDataImage('data:text/html;base64,aGVsbG8=', 10), null);
    assert.equal(Rich.parseDataImage('data:image/png;base64,aGVsbG8=', 4), null);
    assert.equal(Rich.parseDataImage('data:image/svg+xml;base64,PHN2Zz4=', 100), null);
});

test('clipboard_images_obey_count_mime_and_size_limits', () => {
    const files = [
        { type: 'image/png', size: 10, name: 'one.png' },
        { type: 'text/plain', size: 2, name: 'note.txt' },
        { type: 'image/svg+xml', size: 10, name: 'vector.svg' },
        { type: 'image/jpeg', size: 30, name: 'large.jpg' },
        { type: 'image/webp', size: 8, name: 'two.webp' },
        { type: 'image/gif', size: 8, name: 'three.gif' }
    ];
    const result = Rich.selectClipboardImages(files, { maxImages: 2, maxImageBytes: 20 });
    assert.deepEqual(result.accepted.map((file) => file.name), ['one.png', 'two.webp']);
    assert.deepEqual(result.rejected.map((item) => item.reason), ['mime', 'size', 'count']);
});

test('plain_text_fallback_keeps_paragraphs_and_lists', () => {
    assert.equal(
        Rich.plainTextToHtml('Title\n\n• First\n2. Second\n\nFinal'),
        '<p>Title</p><ul><li>First</li></ul><ol><li>Second</li></ol><p>Final</p>'
    );
});

test('plain_text_conversion_escapes_markup', () => {
    assert.equal(Rich.plainTextToHtml('<script>x()</script>'), '<p>&lt;script&gt;x()&lt;/script&gt;</p>');
});

test('media_response_requires_id_and_internal_upload_path', () => {
    assert.deepEqual(Rich.normalizeMediaItem({
        id: 42,
        path: '/storage/uploads/1/photo.png',
        url: 'https://site.test/storage/uploads/1/photo.png',
        name: 'photo.png',
        alt_text: 'Team photo',
        mime_type: 'image/png',
        file_size: 120
    }), {
        id: 42,
        path: '/storage/uploads/1/photo.png',
        url: 'https://site.test/storage/uploads/1/photo.png',
        name: 'photo.png',
        alt: 'Team photo',
        mime: 'image/png',
        bytes: 120
    });
    assert.equal(Rich.normalizeMediaItem({id: 42, path: 'https://evil.test/photo.png'}), null);
    assert.equal(Rich.normalizeMediaItem({id: 42, path: '/storage/uploads/1/../config.php'}), null);
    assert.equal(Rich.normalizeMediaItem({id: 0, path: '/storage/uploads/1/photo.png'}), null);
});

test('external_references_are_separated_from_real_upload_failures', () => {
    assert.deepEqual(Rich.summarizeImageStates([
        { id: 'one', kind: 'remote_url' },
        { id: 'two', kind: 'unresolved' },
        { id: 'three', kind: 'stored' },
        { id: 'four', kind: 'upload_failed' }
    ]), {
        total: 4,
        stored: 1,
        external: 2,
        blocking: 3,
        externalIds: ['one', 'two']
    });
});

test('continue_without_images_discards_only_external_references', () => {
    const images = new Map([
        ['gmail-1', { id: 'gmail-1', kind: 'remote_url' }],
        ['gmail-2', { id: 'gmail-2', kind: 'unresolved' }],
        ['uploaded', { id: 'uploaded', kind: 'stored' }],
        ['failed', { id: 'failed', kind: 'upload_failed' }]
    ]);
    assert.deepEqual(Rich.discardExternalImageReferences(images), ['gmail-1', 'gmail-2']);
    assert.deepEqual(Array.from(images.keys()), ['uploaded', 'failed']);
});

if (!process.exitCode) {
    process.stdout.write('ALL PASS (' + passed + ')\n');
}
