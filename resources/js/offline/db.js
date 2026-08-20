/**
 * IndexedDB local store (15.1) — no external library, ~zero bundle cost.
 * Three stores: `work` (the offline bootstrap feed, one row per section),
 * `outbox` (queued actions awaiting sync, keyed by client UUID) and
 * `meta` (last-sync bookkeeping).
 */
const DB_NAME = 'secondline-offline';
const DB_VERSION = 1;

let dbPromise = null;

function open() {
    if (dbPromise) return dbPromise;

    dbPromise = new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains('work')) db.createObjectStore('work');
            if (!db.objectStoreNames.contains('outbox')) db.createObjectStore('outbox', { keyPath: 'client_id' });
            if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta');
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });

    return dbPromise;
}

function tx(store, mode, operate) {
    return open().then(
        (db) =>
            new Promise((resolve, reject) => {
                const transaction = db.transaction(store, mode);
                const result = operate(transaction.objectStore(store));
                transaction.oncomplete = () => resolve(result.result ?? result);
                transaction.onerror = () => reject(transaction.error);
            }),
    );
}

export const offlineDb = {
    available: typeof indexedDB !== 'undefined',

    getWork(section) {
        return tx('work', 'readonly', (store) => store.get(section));
    },
    putWork(section, data) {
        return tx('work', 'readwrite', (store) => store.put(data, section));
    },

    queue(action) {
        return tx('outbox', 'readwrite', (store) => store.put(action));
    },
    pending() {
        return tx('outbox', 'readonly', (store) => store.getAll());
    },
    remove(clientId) {
        return tx('outbox', 'readwrite', (store) => store.delete(clientId));
    },

    getMeta(key) {
        return tx('meta', 'readonly', (store) => store.get(key));
    },
    putMeta(key, value) {
        return tx('meta', 'readwrite', (store) => store.put(value, key));
    },
};
