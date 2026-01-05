// Mapeo canónico basado en MIC para traducir a códigos de cada proveedor.
// Ajustar/expandir según cobertura real.
const MIC_TO_MARKET = {
  XNAS: 'US',
  XNYS: 'US',
  XASE: 'US',
  OTCM: 'US',
  XBUE: 'AR',
  BVMF: 'BR',
  XLON: 'UK',
  XTSE: 'CA',
  XTSX: 'CA',
  XASX: 'AU',
  XETR: 'DE',
  XPAR: 'FR',
};

// Matriz declarativa de soporte por proveedor (por MIC o mercado agregado).
const PROVIDER_SUPPORT = {
  eodhd: { mics: ['XNAS', 'XNYS', 'OTCM', 'XBUE', 'XLON', 'XTSE', 'XTSX', 'XASX', 'XETR', 'XPAR'] },
  twelvedata: { mics: ['XNAS', 'XNYS', 'XLON', 'XTSE', 'XTSX', 'XASX', 'XETR', 'XPAR', 'XBUE', 'OTCM', 'BVMF'] },
  alphavantage: { markets: ['US'], mics: ['XNAS', 'XNYS', 'OTCM', 'XLON', 'XTSE', 'XTSX', 'XASX', 'XETR', 'XPAR', 'XBUE'] }, // premium puede ampliar
};

const EXCHANGE_MAP = {
  eodhd: {
    XBUE: 'BA',
    XNAS: 'US',
    XNYS: 'US',
    OTCM: 'US',
    XLON: 'LSE',
    XTSE: 'TO',
    XTSX: 'V',
    XASX: 'AU',
    XETR: 'XETRA',
    XPAR: 'PA',
  },
  twelvedata: {
    XNAS: 'XNAS',
    XNYS: 'XNYS',
    XLON: 'XLON',
    XTSE: 'XTSE',
    XTSX: 'XTSX',
    XASX: 'XASX',
    XETR: 'XETR',
    XPAR: 'XPAR',
    XBUE: 'XBUE',
    OTCM: 'OTCM',
  },
  alphavantage: {
    XNAS: '',
    XNYS: '',
    XLON: 'LON',
    XTSE: 'TO',
    XTSX: 'V',
    XASX: 'AX',
    XETR: 'F',
    XPAR: 'PA',
    XBUE: '',
    OTCM: '',
    // Otros mercados suelen no estar soportados en planes básicos.
  },
};

// Cache de disponibilidad por ticker/proveedor/exchange (TTL configurable).
class AvailabilityCache {
  constructor(ttlMs = 300000) {
    this.ttlMs = ttlMs;
    this.mem = new Map();
  }

  key(provider, symbol, mic) {
    return `${provider}::${(symbol ?? '').toUpperCase()}::${mic}`;
  }

  now() {
    return Date.now();
  }

  get(provider, symbol, mic) {
    const entry = this.mem.get(this.key(provider, symbol, mic));
    if (!entry || entry.expiresAt <= this.now()) {
      this.mem.delete(this.key(provider, symbol, mic));
      return null;
    }
    return entry.status;
  }

  set(provider, symbol, mic, status) {
    this.mem.set(this.key(provider, symbol, mic), { status, expiresAt: this.now() + this.ttlMs });
  }

  allows(provider, symbol, mic) {
    const status = this.get(provider, symbol, mic);
    if (status === 'unavailable') return false;
    return true;
  }
}

const availabilityCache = new AvailabilityCache(5 * 60 * 1000);

// Traducción de símbolos por proveedor y MIC.
// Reglas básicas: si el usuario ya envía sufijo (contiene '.'), se respeta.
const mapSymbol = (provider, symbol, exchangeMic) => {
  const base = (symbol ?? '').trim();
  if (!base) return null;
  if (base.includes('.')) return base;

  const mic = (exchangeMic ?? '').toUpperCase();
  const exchangeCode = mapExchange(provider, mic);

  if (provider === 'eodhd') {
    return exchangeCode ? `${base}.${exchangeCode}` : base;
  }

  if (provider === 'twelvedata') {
    // DoceData generalmente acepta símbolo base + exchange separado.
    return base;
  }

  if (provider === 'alphavantage') {
    return exchangeCode ? `${base}.${exchangeCode}` : base;
  }

  return base;
};

const mapExchange = (provider, exchangeMic) => {
  if (!exchangeMic) return '';
  const mic = exchangeMic.toUpperCase();
  const map = EXCHANGE_MAP[provider] || {};
  if (provider === 'alphavantage') {
    return map[mic] ?? '';
  }
  // Si no hay mapping explícito, devolvemos mic tal cual (algunos providers aceptan MIC).
  return map[mic] ?? mic;
};

const providerSupports = (provider, mic) => {
  const support = PROVIDER_SUPPORT[provider] || {};
  const hasMics = Array.isArray(support.mics) && support.mics.length > 0;
  if (hasMics && support.mics.includes(mic)) return true;

  if (support.markets && support.markets.length > 0) {
    const market = MIC_TO_MARKET[mic];
    if (market) {
      return support.markets.includes(market);
    }
  }

  if (hasMics) return false;
  return true; // si no hay restricciones declaradas, se asume soportado
};

export const mappingAdapter = {
  mapForProvider(provider, { symbol, exchange }) {
    const providerSymbol = mapSymbol(provider, symbol, exchange);
    if (!providerSymbol) return null;
    const providerExchange = mapExchange(provider, exchange);
    return { symbol: providerSymbol, exchange: providerExchange };
  },
  eligibleProviders(providers, { symbol, exchange }, { ignoreAvailability = false } = {}) {
    const mic = (exchange ?? '').toUpperCase();
    return providers.filter((p) => {
      if (!providerSupports(p.name, mic)) return false;
      if (ignoreAvailability) return true;
      return availabilityCache.allows(p.name, symbol, mic);
    });
  },
  markAvailability(provider, symbol, exchange, available) {
    const mic = (exchange ?? '').toUpperCase();
    availabilityCache.set(provider, symbol, mic, available ? 'available' : 'unavailable');
  },
  MIC_TO_MARKET,
  PROVIDER_SUPPORT,
};
