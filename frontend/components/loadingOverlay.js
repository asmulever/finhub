const STYLE_ID = 'app-loading-overlay-style';

const injectStyles = () => {
  if (document.getElementById(STYLE_ID)) return;
  const style = document.createElement('style');
  style.id = STYLE_ID;
  style.textContent = `
    .app-loading-overlay {
      position: fixed;
      inset: 0;
      background: rgba(3, 7, 18, 0.78);
      backdrop-filter: blur(2px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.18s ease;
    }
    .app-loading-overlay.visible {
      opacity: 1;
      pointer-events: all;
    }
    .app-loading-spinner {
      width: 70px;
      height: 70px;
      border-radius: 50%;
      border: 6px solid rgba(148, 163, 184, 0.35);
      border-top-color: #22d3ee;
      animation: app-spin 0.9s linear infinite;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
    }
    @keyframes app-spin {
      to { transform: rotate(360deg); }
    }
  `;
  document.head.appendChild(style);
};

/** Crea un overlay reutilizable con spinner centrado. */
export const createLoadingOverlay = () => {
  injectStyles();
  const overlay = document.createElement('div');
  overlay.className = 'app-loading-overlay';

  const spinner = document.createElement('div');
  spinner.className = 'app-loading-spinner';
  overlay.appendChild(spinner);

  const state = { counter: 0 };

  const show = () => {
    try {
      state.counter += 1;
      if (!overlay.isConnected) {
        document.body.appendChild(overlay);
      }
      overlay.classList.add('visible');
    } catch (error) {
      console.info('[loadingOverlay] No se pudo mostrar overlay', error);
    }
  };

  const hide = () => {
    try {
      state.counter = Math.max(0, state.counter - 1);
      if (state.counter === 0) {
        overlay.classList.remove('visible');
      }
    } catch (error) {
      console.info('[loadingOverlay] No se pudo ocultar overlay', error);
    }
  };

  const withLoader = async (fn) => {
    show();
    try {
      return await fn();
    } catch (error) {
      console.info('[loadingOverlay] Error durante operación envuelta', error);
      throw error;
    } finally {
      hide();
    }
  };

  return { show, hide, withLoader };
};
