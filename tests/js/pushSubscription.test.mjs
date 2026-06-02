import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import {
    urlBase64ToUint8Array,
    subscriptionMatchesVapid,
    pushErrorMessage,
} from '../../resources/js/lib/pushSubscription.js';

globalThis.atob = (value) => Buffer.from(value, 'base64').toString('binary');

describe('pushSubscription', () => {
    it('urlBase64ToUint8Array decodes padded base64url', () => {
        const key = urlBase64ToUint8Array('AQID');
        assert.ok(key instanceof Uint8Array);
        assert.ok(key.length > 0);
    });

    it('subscriptionMatchesVapid returns false without subscription', () => {
        assert.equal(subscriptionMatchesVapid(null, 'AQID'), false);
    });

    it('pushErrorMessage maps known reasons', () => {
        assert.match(pushErrorMessage('subscription_sync_failed'), /sincronizar/i);
        assert.match(pushErrorMessage('unknown_code'), /não foi possível/i);
    });
});
