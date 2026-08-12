import axios from 'axios';
import { offlineDb } from './db';

/**
 * The durable outbox and sync engine (15.1).
 *
 * queueAction() records the user's act with a client-generated UUID (the
 * idempotency key) and tries to sync immediately; offline it just stays
 * queued. syncNow() replays the queue IN ORDER; the server applies each
 * action idempotently and returns one outcome per action:
 *
 *  - applied   → removed from the queue
 *  - rejected  → removed, but surfaced with the server's reason (an
 *                offline-queued approval lands here by design)
 *  - conflict  → removed and surfaced with the server state so the UI
 *                shows a "server changed this" prompt — never a silent
 *                overwrite; the user re-acts from the fresh copy.
 *
 * Subscribers get {pending, lastSyncAt, syncing, issues} for the status
 * indicator; `issues` accumulates rejections/conflicts until acknowledged.
 */
const listeners = new Set();

const state = {
    pending: 0,
    syncing: false,
    lastSyncAt: null,
    issues: [],
};

function emit() {
    listeners.forEach((listener) => listener({ ...state, issues: [...state.issues] }));
}

async function refreshPending() {
    if (!offlineDb.available) return;
    const all = await offlineDb.pending();
    state.pending = all.length;
    emit();
}

export function subscribeOutbox(listener) {
    listeners.add(listener);
    listener({ ...state, issues: [...state.issues] });
    refreshPending();

    return () => listeners.delete(listener);
}

export function acknowledgeIssue(clientId) {
    state.issues = state.issues.filter((issue) => issue.client_id !== clientId);
    emit();
}

export async function queueAction(type, payload) {
    const action = {
        client_id: crypto.randomUUID(),
        type,
        payload,
        acted_at: new Date().toISOString(),
    };

    await offlineDb.queue(action);
    await refreshPending();

    if (navigator.onLine) await syncNow();

    return action.client_id;
}

export async function syncNow() {
    if (state.syncing || !offlineDb.available) return;

    const actions = (await offlineDb.pending()).sort((a, b) => a.acted_at.localeCompare(b.acted_at));
    if (actions.length === 0) return;

    state.syncing = true;
    emit();

    // Evidence captured offline carries a Blob — replayed as a normal
    // multipart upload, not through the JSON outbox endpoint.
    const evidenceActions = actions.filter((a) => a.type === 'evidence.capture');
    const jsonActions = actions.filter((a) => a.type !== 'evidence.capture');

    for (const action of evidenceActions) {
        try {
            const form = new FormData();
            form.append('file', action.payload.blob, action.payload.file_name);
            Object.entries(action.payload.meta ?? {}).forEach(([key, value]) => form.append(key, value ?? ''));

            await axios.post(route('evidence.store'), form);
            await offlineDb.remove(action.client_id);
        } catch (error) {
            if (error.response && error.response.status < 500) {
                // The server understood and refused — surface it, stop retrying.
                state.issues.push({
                    client_id: action.client_id,
                    status: 'rejected',
                    reason: error.response.data?.message ?? 'The evidence upload was rejected.',
                    action,
                });
                await offlineDb.remove(action.client_id);
            }
            // Network/server errors leave it queued for the next sync.
        }
    }

    if (jsonActions.length === 0) {
        state.syncing = false;
        await refreshPending();
        return;
    }

    try {
        const { data } = await axios.post(route('offline.outbox'), { actions: jsonActions });

        for (const outcome of data.outcomes) {
            await offlineDb.remove(outcome.client_id);

            if (outcome.status !== 'applied') {
                const original = actions.find((a) => a.client_id === outcome.client_id);
                state.issues.push({ ...outcome, action: original });
            }
        }

        state.lastSyncAt = data.synced_at;
        await offlineDb.putMeta('lastSyncAt', data.synced_at);
    } catch {
        // Still offline or the server is unreachable — the queue is durable,
        // the next connectivity event retries.
    } finally {
        state.syncing = false;
        await refreshPending();
    }
}

/** Refresh the cached working set after a successful connection. */
export async function refreshBootstrap() {
    if (!offlineDb.available) return null;

    try {
        const { data } = await axios.get(route('offline.bootstrap'));

        await Promise.all([
            offlineDb.putWork('test_instances', data.test_instances),
            offlineDb.putWork('csa_responses', data.csa_responses),
            offlineDb.putWork('attestations', data.attestations),
            offlineDb.putWork('controls', data.controls),
            offlineDb.putMeta('bootstrappedAt', data.generated_at),
        ]);

        return data;
    } catch {
        return null;
    }
}

let started = false;

/** Wire the connectivity listeners once per app boot. */
export function startSyncEngine() {
    if (started || typeof window === 'undefined' || !offlineDb.available) return;
    started = true;

    window.addEventListener('online', () => {
        syncNow();
        refreshBootstrap();
    });

    offlineDb.getMeta('lastSyncAt').then((value) => {
        if (value) {
            state.lastSyncAt = value;
            emit();
        }
    });

    if (navigator.onLine) {
        syncNow();
    }

    refreshPending();
}
