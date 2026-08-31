import { useEffect, useState, useCallback } from 'react';

/**
 * Oddiy ma'lumot yuklash hook'i: { loading, data, error, reload }.
 * `deps` o'zgarsa qayta yuklaydi (masalan manzil yoki tuman filtri).
 */
export function useAsync(fn, deps = []) {
    const [state, setState] = useState({ loading: true, data: null, error: null });

    const run = useCallback(() => {
        let alive = true;
        setState((s) => ({ ...s, loading: true, error: null }));
        Promise.resolve()
            .then(fn)
            .then(
                (data) => alive && setState({ loading: false, data, error: null }),
                (error) => alive && setState({ loading: false, data: null, error }),
            );
        return () => {
            alive = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, deps);

    useEffect(run, [run]);

    return { ...state, reload: run };
}
