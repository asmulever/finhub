import { getJson } from '../apicliente.js';
import { mappingAdapter } from './marketDataMapping.js';

// Cache TTL simple en memoria con opción de persistencia localStorage.
class TTLCache {
  constructor({ defaultTtlMs = 30000, persist = false, storageKey = 'md_cache_v1' } = {}) {
    this.defaultTtlMs = defaultTtlMs;
    this.persist = persist;
    this.storageKey = storageKey;
    this.mem = new Map();
    if (this.persist) this.load();
  }

  now() {
    return Date.now();
  }

  load() {
    try {
      const raw = localStorage.getItem(this.storageKey);
      if (!raw) return;
      const parsed = JSON.parse(raw);
      Object.entries(parsed || {}).forEach(([k, v]) => {
        if (v && typeof v.expiresAt === 'number') {
          this.mem.set(k, v);
        }
      });
      this.gc();
    } catch {
      // ignorar errores de storage
    }
  }

  save() {
    if (!this.persist) return;
    try {
      const obj = Object.fromEntries(this.mem.entries());
      localStorage.setItem(this.storageKey, JSON.stringify(obj));
    } catch {
      // ignorar errores de storage
    }
  }

  gc() {
    const now = this.now();
    for (const [k, v] of this.mem.entries()) {
      if (!v || v.expiresAt <= now) {
        this.mem.delete(k);
      }
    }
    this.save();
  }

  get(key) {
    const v = this.mem.get(key);
    if (!v) return null;
    if (v.expiresAt <= this.now()) {
      this.mem.delete(key);
      this.save();
      return null;
    }
    return v.value;
  }

  set(key, value, ttlMs = this.defaultTtlMs) {
    const expiresAt = this.now() + ttlMs;
    this.mem.set(key, { value, expiresAt });
    this.save();
  }
}

// Rate limiter local por ventana.
class WindowRateLimiter {
  constructor({ limits }) {
    this.limits = limits.map((l) => ({ ...l }));
    this.calls = new Map();
  }

  now() {
    return Date.now();
  }

  prune(limitName, windowMs) {
    const now = this.now();
    const arr = (this.calls.get(limitName) || []).filter((ts) => (now - ts) < windowMs);
    this.calls.set(limitName, arr);
    return arr;
  }

  canCall() {
    return this.limits.every((lim) => this.prune(lim.name, lim.windowMs).length < lim.max);
  }

  scoreRemaining() {
    return this.limits.reduce((acc, lim) => {
      const remaining = Math.max(0, lim.max - this.prune(lim.name, lim.windowMs).length);
      const weight = lim.windowMs <= 60000 ? 5 : (lim.windowMs <= 3600000 ? 2 : 1);
      return acc + remaining * weight;
    }, 0);
  }

  recordCall() {
    const now = this.now();
    this.limits.forEach((lim) => {
      const arr = this.calls.get(lim.name) || [];
      arr.push(now);
      this.calls.set(lim.name, arr);
    });
  }
}

// Utilidades de normalización.
const normStr = (s) => (s ?? '').toString().trim();
const up = (s) => normStr(s).toUpperCase();
const makeCacheKey = (op, params) => {
  const payload = { op, ...params };
  return Object.keys(payload).sort().map((k) => `${k}=${encodeURIComponent(payload[k] ?? '')}`).join('&');
};

class ProviderBase {
  constructor(name, limiter, priority = 0) {
    this.name = name;
    this.limiter = limiter;
    this.cooldownUntil = 0;
    this.priority = priority;
  }

  inCooldown() {
    return Date.now() < this.cooldownUntil;
  }

  setCooldown(ms) {
    this.cooldownUntil = Date.now() + ms;
  }

  canCallNow() {
    return !this.inCooldown() && this.limiter.canCall();
  }

  score() {
    return this.inCooldown() ? -1 : this.limiter.scoreRemaining();
  }

  recordCall() {
    this.limiter.recordCall();
  }

  supports() {
    return true;
  }
}

class TwelveDataProvider extends ProviderBase {
  supports(_op, params) {
    const exchange = up(params.exchange);
    return true;
  }

  async quote(params) {
    this.recordCall();
    const qs = new URLSearchParams();
    qs.set('symbol', normStr(params.symbol));
    if (params.exchange) qs.set('exchange', normStr(params.exchange));
    return getJson(`/twelvedata/quote?${qs.toString()}`);
  }

  async timeSeries(params) {
    this.recordCall();
    const qs = new URLSearchParams();
    qs.set('symbol', normStr(params.symbol));
    qs.set('interval', normStr(params.interval || '1day'));
    if (params.outputsize) qs.set('outputsize', normStr(params.outputsize));
    if (params.exchange) qs.set('exchange', normStr(params.exchange));
    return getJson(`/twelvedata/time_series?${qs.toString()}`);
  }
}

class EodhdProvider extends ProviderBase {
  supports() {
    return true;
  }

  async quote(params) {
    this.recordCall();
    const qs = new URLSearchParams();
    qs.set('symbol', normStr(params.symbol));
    if (params.exchange) qs.set('exchange', normStr(params.exchange));
    return getJson(`/eodhd/quote?${qs.toString()}`);
  }

  async timeSeries(params) {
    this.recordCall();
    const qs = new URLSearchParams();
    qs.set('symbol', normStr(params.symbol));
    if (params.exchange) qs.set('exchange', normStr(params.exchange));
    if (params.interval) qs.set('interval', normStr(params.interval));
    if (params.outputsize) qs.set('outputsize', normStr(params.outputsize));
    return getJson(`/eodhd/eod?${qs.toString()}`);
  }
}

class AlphaVantageProvider extends ProviderBase {
  supports(_op, params) {
    const exchange = up(params.exchange);
    if (exchange === 'XBUE') return false;
    return true;
  }

  async quote(params) {
    this.recordCall();
    const qs = new URLSearchParams();
    qs.set('symbol', normStr(params.symbol));
    return getJson(`/alphavantage/quote?${qs.toString()}`);
  }

  async timeSeries(params) {
    this.recordCall();
    const qs = new URLSearchParams();
    qs.set('symbol', normStr(params.symbol));
    qs.set('interval', normStr(params.interval || '1day'));
    if (params.outputsize) qs.set('outputsize', normStr(params.outputsize));
    return getJson(`/alphavantage/time_series?${qs.toString()}`);
  }
}

class MarketDataFacade {
  constructor({ providers, cache, defaultCacheTtlMs = 20000, failCooldownMs = 60000 } = {}) {
    this.providers = providers;
    this.cache = cache || new TTLCache({ defaultTtlMs: defaultCacheTtlMs, persist: false });
    this.defaultCacheTtlMs = defaultCacheTtlMs;
    this.failCooldownMs = failCooldownMs;
  }

  pickProviders(op, params, preferred) {
    const supported = this.providers
      .filter((p) => p.supports(op, params))
      .filter((p) => p.canCallNow());

    let eligible = mappingAdapter.eligibleProviders(supported, params);
    if (!eligible.length) {
      // Si todos están marcados como no disponibles en cache, se permite ignorar ese flag para reintentar luego de TTL.
      eligible = mappingAdapter.eligibleProviders(supported, params, { ignoreAvailability: true });
    }

    return eligible.sort((a, b) => {
      if (preferred && a.name === preferred && b.name !== preferred) return -1;
      if (preferred && b.name === preferred && a.name !== preferred) return 1;
      if (a.priority !== b.priority) return b.priority - a.priority;
      return b.score() - a.score();
    });
  }

  async callWithFallback(op, params, ttlMs, preferred) {
    const cacheKey = makeCacheKey(op, { ...params, preferred });
    const cached = this.cache.get(cacheKey);
    if (cached) {
      return { provider: cached.__provider, cached: true, data: cached.data };
    }

    let candidates = this.pickProviders(op, params, preferred);
    if (!candidates.length) {
      candidates = this.providers.filter((p) => p.supports(op, params)).sort((a, b) => b.score() - a.score());
    }

    let lastErr = null;

    for (const p of candidates) {
      const mapped = mappingAdapter.mapForProvider(p.name, { symbol: params.symbol, exchange: params.exchange });
      if (!mapped) {
        continue;
      }
      try {
        let res;
        if (op === 'quote') res = await p.quote({ ...params, symbol: mapped.symbol, exchange: mapped.exchange });
        else if (op === 'timeSeries') res = await p.timeSeries({ ...params, symbol: mapped.symbol, exchange: mapped.exchange });
        else throw new Error('Operación no soportada');

        mappingAdapter.markAvailability(p.name, params.symbol, params.exchange, true);
        this.cache.set(cacheKey, { __provider: p.name, data: res }, ttlMs ?? this.defaultCacheTtlMs);
        return { provider: p.name, cached: false, data: res };
      } catch (err) {
        lastErr = err;
        if (this.isDataAvailabilityError(err)) {
          mappingAdapter.markAvailability(p.name, params.symbol, params.exchange, false);
        }
        const msg = (err && (err.message || err.toString()) || '').toString().toLowerCase();
        if (msg.includes('429') || msg.includes('rate limit')) {
          p.setCooldown(this.failCooldownMs);
        }
      }
    }

    throw lastErr || new Error('Sin proveedores disponibles');
  }

  quote({ symbol, exchange = '', cacheTtlMs, preferred } = {}) {
    return this.callWithFallback('quote', { symbol, exchange }, cacheTtlMs, preferred);
  }

  timeSeries({ symbol, exchange = '', interval = '1day', outputsize = '365', cacheTtlMs, preferred } = {}) {
    return this.callWithFallback('timeSeries', { symbol, exchange, interval, outputsize }, cacheTtlMs, preferred);
  }

  isDataAvailabilityError(err) {
    const msg = (err && (err.message || err.toString()) || '').toString().toLowerCase();
    if (msg.includes('not found') || msg.includes('no data') || msg.includes('no content')) return true;
    if (msg.includes('invalid api call') || msg.includes('unsupported') || msg.includes('unknown symbol')) return true;
    if (msg.includes('404')) return true;
    return false;
  }
}

const defaultCache = new TTLCache({ defaultTtlMs: 30000, persist: true });

export const marketDataFacade = new MarketDataFacade({
  cache: defaultCache,
  providers: [
    new EodhdProvider('eodhd', new WindowRateLimiter({
      limits: [
        { name: 'eodhd_60s', windowMs: 60000, max: 60 },
        { name: 'eodhd_1d', windowMs: 86400000, max: 5000 },
      ],
    }), 0),
    new TwelveDataProvider('twelvedata', new WindowRateLimiter({
      limits: [
        { name: 'td_60s', windowMs: 60000, max: 8 },
        { name: 'td_1d', windowMs: 86400000, max: 800 },
      ],
    }), 2),
    new AlphaVantageProvider('alphavantage', new WindowRateLimiter({
      limits: [
        { name: 'av_60s', windowMs: 60000, max: 5 },
        { name: 'av_1d', windowMs: 86400000, max: 500 },
      ],
    }), 1),
  ],
});
