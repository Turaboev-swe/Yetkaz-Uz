import assert from 'node:assert/strict';
import { test } from 'node:test';

// api.js modul yuklanganda window.__KITCHEN__ ni o'qiydi — node'da yo'q, shuning uchun o'rnatamiz.
globalThis.window = { __KITCHEN__: { csrf: 'x' } };
const { createPrintQueue, imagesReady, receiptUrl, shouldAutoPrint } = await import('./browserPrint.js');

const order = (status, browser = true) => ({ id: 7, status, restaurant: { id: 1, browser_print: browser } });

test('auto print happens only for new -> accepted in a browser-print restaurant', () => {
    assert.equal(shouldAutoPrint(order('new'), order('accepted')), true);
});

test('no auto print for other transitions', () => {
    assert.equal(shouldAutoPrint(order('accepted'), order('preparing')), false);
    assert.equal(shouldAutoPrint(order('preparing'), order('on_the_way')), false);
    assert.equal(shouldAutoPrint(order('new'), order('new')), false);
});

test('no auto print when the restaurant is not in browser mode or data is missing', () => {
    assert.equal(shouldAutoPrint(order('new', false), order('accepted', false)), false);
    assert.equal(shouldAutoPrint(undefined, order('accepted')), false);
    assert.equal(shouldAutoPrint(order('new'), undefined), false);
    assert.equal(shouldAutoPrint(order('new'), { id: 7, status: 'accepted' }), false);
});

test('receipt url points at the kitchen receipt page', () => {
    assert.equal(receiptUrl(42), '/kitchen/orders/42/receipt');
});

function fakeImage(complete) {
    const handlers = {};
    return {
        complete,
        addEventListener: (name, fn) => {
            handlers[name] = fn;
        },
        fire: (name) => handlers[name]?.(),
    };
}

test('imagesReady resolves at once when every image is already loaded', async () => {
    await imagesReady({ images: [fakeImage(true), fakeImage(true)] }, 50);
    await imagesReady({ images: [] }, 50);
});

test('imagesReady waits for pending images before resolving', async () => {
    const img = fakeImage(false);
    let done = false;
    const p = imagesReady({ images: [img] }, 5000).then(() => {
        done = true;
    });

    await new Promise((r) => setTimeout(r, 10));
    assert.equal(done, false, 'rasm yuklanmaguncha kutadi');

    img.fire('load');
    await p;
    assert.equal(done, true);
});

test('a broken image does not block printing forever', async () => {
    const img = fakeImage(false);
    const p = imagesReady({ images: [img] }, 5000);
    img.fire('error');
    await p;
});

test('imagesReady gives up after the timeout (receipt prints without the logo)', async () => {
    const started = Date.now();
    await imagesReady({ images: [fakeImage(false)] }, 30);
    assert.ok(Date.now() - started < 1000);
});

test('print queue runs jobs one at a time, in order', async () => {
    const log = [];
    const queue = createPrintQueue(async (id) => {
        log.push(`start ${id}`);
        await new Promise((r) => setTimeout(r, 15));
        log.push(`end ${id}`);
    });

    await Promise.all([queue(1), queue(2), queue(3)]);

    assert.deepEqual(log, ['start 1', 'end 1', 'start 2', 'end 2', 'start 3', 'end 3']);
});

test('a failed job rejects for its caller but does not block the next one', async () => {
    const queue = createPrintQueue(async (id) => {
        if (id === 1) throw new Error('printer yo‘q');
        return id;
    });

    const first = queue(1);
    const second = queue(2);

    await assert.rejects(first, /printer yo‘q/);
    assert.equal(await second, 2);
});
