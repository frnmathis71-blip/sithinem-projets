function validateSettings(input) {
  if (!input || typeof input !== 'object') throw new Error('Paramètres invalides.');
  let url;
  try { url = new URL(input.siteUrl); } catch { throw new Error('Saisissez une adresse HTTPS valide.'); }
  if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash || url.pathname !== '/') {
    throw new Error('Utilisez la racine HTTPS du site, par exemple https://restaurant.fr.');
  }
  if (typeof input.printer !== 'string' || input.printer.length > 256 || /[\r\n\0]/.test(input.printer)) throw new Error('Imprimante invalide.');
  if (![58, 80].includes(input.width)) throw new Error('Choisissez 58 ou 80 mm.');
  if (typeof input.token !== 'string' || input.token.length > 1024 || (input.token && !/^[\x21-\x7e]{32,1024}$/.test(input.token))) throw new Error('Le jeton doit contenir au moins 32 caractères, sans espace.');
  return { siteUrl: url.origin, printer: input.printer, width: input.width, token: input.token, printing: input.printing === true, startup: input.startup === true };
}
module.exports = { validateSettings };
