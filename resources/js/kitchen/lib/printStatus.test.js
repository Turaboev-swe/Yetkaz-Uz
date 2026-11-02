import assert from 'node:assert/strict';
import { test } from 'node:test';
import { agentTransition, offlineAgents, printerRestaurants, shouldPingFailed } from './printStatus.js';

const r = (id, over = {}) => ({ id, name: `R${id}`, uses_printer: true, agent_online: true, ...over });

test('only restaurants with a configured print agent are considered', () => {
    const list = [r(1), r(2, { uses_printer: false, agent_online: false }), r(3, { agent_online: false })];

    assert.deepEqual(printerRestaurants(list).map((x) => x.id), [1, 3]);
    assert.deepEqual(offlineAgents(list).map((x) => x.id), [3]);
});

test('a restaurant without a printer never produces a banner, even with a silent agent', () => {
    assert.deepEqual(offlineAgents([r(1, { uses_printer: false, agent_online: false })]), []);
});

test('first load while the agent is already offline: banner but no signal', () => {
    const { next, wentOffline } = agentTransition({}, [r(1, { agent_online: false })]);

    assert.equal(wentOffline, false);
    assert.deepEqual(next, { 1: 'offline' });
});

test('online -> offline signals exactly once, then stays quiet while offline', () => {
    let state = agentTransition({}, [r(1)]).next; // yuklash: onlayn
    const first = agentTransition(state, [r(1, { agent_online: false })]);
    assert.equal(first.wentOffline, true);

    state = first.next;
    for (let i = 0; i < 5; i++) {
        const again = agentTransition(state, [r(1, { agent_online: false })]);
        assert.equal(again.wentOffline, false, `qayta so'rov ${i + 1}: signal yo'q`);
        state = again.next;
    }
});

test('reconnect clears the offline state and the next outage signals again', () => {
    let state = { 1: 'offline' };

    const back = agentTransition(state, [r(1)]);
    assert.equal(back.wentOffline, false);
    assert.deepEqual(offlineAgents([r(1)]), []); // banner o'zi yo'qoladi
    state = back.next;

    assert.equal(agentTransition(state, [r(1, { agent_online: false })]).wentOffline, true);
});

test('with several restaurants only the one that dropped triggers the signal', () => {
    const prev = { 1: 'online', 2: 'offline' };

    assert.equal(agentTransition(prev, [r(1), r(2, { agent_online: false })]).wentOffline, false);
    assert.equal(agentTransition(prev, [r(1, { agent_online: false }), r(2, { agent_online: false })]).wentOffline, true);
});

test('a restaurant that stops using a printer drops out of the state', () => {
    const { next } = agentTransition({ 1: 'online' }, [r(1, { uses_printer: false })]);

    assert.deepEqual(next, {});
});

test('failed-order ping only when the count grows and no offline banner is up', () => {
    assert.equal(shouldPingFailed(0, 1, 0), true);
    assert.equal(shouldPingFailed(1, 1, 0), false);
    assert.equal(shouldPingFailed(2, 1, 0), false);
    assert.equal(shouldPingFailed(0, 1, 1), false);
});
