import assert from 'node:assert/strict';
import { test } from 'node:test';
import { composeAddress, streetOrLandmarkMissing } from './addressForm.js';

test('compose joins street, locality and district', () => {
    assert.equal(composeAddress("Bobur ko'chasi 12", 'Yangi hayot', "Qo'rg'ontepa tumani"), "Bobur ko'chasi 12, Yangi hayot, Qo'rg'ontepa tumani");
});

test('compose skips missing parts', () => {
    assert.equal(composeAddress('', 'Yangi hayot', 'Xonobod shahri'), 'Yangi hayot, Xonobod shahri');
    assert.equal(composeAddress(null, null, 'Xonobod shahri'), 'Xonobod shahri');
    assert.equal(composeAddress("Bobur ko'chasi", '  ', null), "Bobur ko'chasi");
    assert.equal(composeAddress('', '', ''), '');
});

test('compose does not repeat the district or the locality', () => {
    assert.equal(composeAddress("Bobur ko'chasi, Qo‘rg‘ontepa tumani", null, "QO'RG'ONTEPA TUMANI"), "Bobur ko'chasi, Qo‘rg‘ontepa tumani");
    assert.equal(composeAddress('', 'Xonobod', 'Xonobod shahri'), 'Xonobod shahri');
    assert.equal(composeAddress("Yangi hayot ko'chasi", 'Yangi hayot', 'Xonobod shahri'), "Yangi hayot ko'chasi, Xonobod shahri");
});

test('street or landmark is missing only when both are blank', () => {
    assert.equal(streetOrLandmarkMissing('', ''), true);
    assert.equal(streetOrLandmarkMissing('   ', '\t'), true);
    assert.equal(streetOrLandmarkMissing(null, undefined), true);
    assert.equal(streetOrLandmarkMissing('Navoiy', ''), false, 'faqat ko‘cha yetarli');
    assert.equal(streetOrLandmarkMissing('', 'Bozor yonida'), false, 'faqat mo‘ljal yetarli');
    assert.equal(streetOrLandmarkMissing('Navoiy', 'Bozor yonida'), false);
});
