export const setupPasswordToggle = (input, button) => {
  const setState = (visible) => {
    input.type = visible ? 'text' : 'password';
    button.textContent = visible ? '🙈' : '👁️';
    button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
  };
  button.addEventListener('click', (event) => {
    event.preventDefault();
    setState(input.type !== 'text');
  });
  setState(false);
};
