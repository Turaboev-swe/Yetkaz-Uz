import assert from 'node:assert/strict';
import { test } from 'node:test';
import { applyShortages, canAddMore, clampQty, isLimited, stockNoticeText, syncLines } from './stock.js';

const line = (name, qty, extra = {}) => ({ qty, price: 1000, name, ...extra });

test('null and undefined stock mean unlimited', () => {
    assert.equal(isLimited(null), false);
    assert.equal(isLimited(undefined), false);
    assert.equal(isLimited(0), true);
    assert.equal(isLimited(7), true);
});

test('cannot add more than the stock, can always add when unlimited', () => {
    assert.equal(canAddMore(3, false, 2), true);
    assert.equal(canAddMore(3, false, 3), false, 'qoldiqqa yetdi');
    assert.equal(canAddMore(null, false, 500), true, 'cheklanmagan');
    assert.equal(canAddMore(undefined, false, 500), true);
});

test('a sold out product can never be added', () => {
    assert.equal(canAddMore(0, true, 0), false);
    assert.equal(canAddMore(0, false, 0), false, 'stock 0 — qo‘shib bo‘lmaydi');
    assert.equal(canAddMore(5, true, 0), false, 'sold_out belgisi ustun');
});

test('clampQty cuts to the stock and leaves unlimited alone', () => {
    assert.equal(clampQty(5, 3), 3);
    assert.equal(clampQty(2, 3), 2);
    assert.equal(clampQty(5, 0), 0);
    assert.equal(clampQty(5, null), 5);
    assert.equal(clampQty(5, -2), 0, 'manfiy bo‘lmaydi');
});

test('syncLines reduces and removes lines to match the menu stock', () => {
    const lines = { 1: line('Osh', 5), 2: line('Non', 2), 3: line('Choy', 4), 4: line('Eski', 1) };
    const products = [
        { id: 1, stock: 3, sold_out: false },
        { id: 2, stock: 0, sold_out: true },
        { id: 3, stock: null, sold_out: false },
    ];

    const { lines: next, removed, reduced } = syncLines(lines, products);

    assert.equal(next[1].qty, 3);
    assert.equal(next[1].stock, 3, 'savat qatori limitni eslab qoladi');
    assert.equal(next[2], undefined, 'tugagan olib tashlandi');
    assert.equal(next[3].qty, 4);
    assert.equal(next[3].stock, null);
    assert.equal(next[4].qty, 1, 'menyuda yo‘q qator o‘zgarmaydi (mavjudlikni server hal qiladi)');
    assert.deepEqual(removed, ['Non']);
    assert.deepEqual(reduced, [{ name: 'Osh', from: 5, to: 3 }]);
});

test('syncLines does not touch lines that already fit', () => {
    const { lines, removed, reduced } = syncLines({ 1: line('Osh', 2) }, [{ id: 1, stock: 10, sold_out: false }]);

    assert.equal(lines[1].qty, 2);
    assert.deepEqual([removed, reduced], [[], []]);
});

test('a restocked product keeps its quantity and gets the new limit', () => {
    const { lines } = syncLines({ 1: line('Osh', 2, { stock: 0 }) }, [{ id: 1, stock: 9, sold_out: false }]);

    assert.equal(lines[1].qty, 2);
    assert.equal(lines[1].stock, 9);
});

test('applyShortages cuts to what the server says is available', () => {
    const lines = { 1: line('Osh', 4), 2: line('Non', 2) };

    const { lines: next, removed, reduced } = applyShortages(lines, [
        { product_id: 1, available: 1 },
        { product_id: 2, available: 0 },
    ]);

    assert.equal(next[1].qty, 1);
    assert.equal(next[2], undefined);
    assert.deepEqual(removed, ['Non']);
    assert.deepEqual(reduced, [{ name: 'Osh', from: 4, to: 1 }]);
});

test('applyShortages ignores lines the server did not mention', () => {
    const { lines } = applyShortages({ 1: line('Osh', 4), 2: line('Non', 2) }, [{ product_id: 1, available: 2 }]);

    assert.equal(lines[2].qty, 2);
});

test('notice text is uzbek and empty when nothing changed', () => {
    assert.equal(stockNoticeText({ removed: [], reduced: [] }), null);
    assert.equal(
        stockNoticeText({ removed: ['Non'], reduced: [{ name: 'Osh', from: 5, to: 3 }] }),
        'Osh: faqat 3 ta qoldi, savat yangilandi. Non tugadi va savatdan olib tashlandi.',
    );
});
