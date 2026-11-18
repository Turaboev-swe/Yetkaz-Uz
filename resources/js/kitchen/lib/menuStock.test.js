import assert from 'node:assert/strict';
import { test } from 'node:test';
import { buttonLabel, filterCategories, nextSoldOut, replaceProduct } from './menuStock.js';

const p = (id, name, extra = {}) => ({ id, name, stock: null, sold_out: false, hidden: false, ...extra });

test('button says Tugadi when the stock is zero', () => {
    assert.equal(buttonLabel(p(1, 'Osh', { stock: 0, sold_out: true })), 'Tugadi');
});

test('button says Bor with the count for limited stock and plain Bor for unlimited', () => {
    assert.equal(buttonLabel(p(1, 'Osh', { stock: 7 })), 'Bor · 7 ta');
    assert.equal(buttonLabel(p(1, 'Osh', { stock: null })), 'Bor');
    assert.equal(buttonLabel(p(1, 'Osh', { stock: undefined })), 'Bor');
});

test('tapping flips the state explicitly', () => {
    assert.equal(nextSoldOut(p(1, 'Osh')), true);
    assert.equal(nextSoldOut(p(1, 'Osh', { stock: 0, sold_out: true })), false);
});

test('search ignores case and surrounding spaces and drops empty categories', () => {
    const cats = [
        { id: 1, name: 'Taomlar', products: [p(1, "Lag'mon"), p(2, 'Osh')] },
        { id: 2, name: 'Ichimlik', products: [p(3, 'Cola')] },
    ];

    assert.deepEqual(filterCategories(cats, '  LAG').map((c) => c.products.map((x) => x.id)), [[1]]);
    assert.equal(filterCategories(cats, 'yo‘q-taom').length, 0);
});

test('an empty search returns everything untouched', () => {
    const cats = [{ id: 1, name: 'Taomlar', products: [p(1, 'Osh')] }];

    assert.equal(filterCategories(cats, ''), cats);
    assert.equal(filterCategories(cats, null), cats);
});

test('replaceProduct swaps only the updated dish', () => {
    const cats = [{ id: 1, name: 'Taomlar', products: [p(1, 'Osh'), p(2, 'Non')] }];
    const next = replaceProduct(cats, p(2, 'Non', { stock: 0, sold_out: true }));

    assert.equal(next[0].products[0], cats[0].products[0]);
    assert.equal(next[0].products[1].sold_out, true);
    assert.equal(cats[0].products[1].sold_out, false, 'asl ro‘yxat o‘zgarmaydi');
});
