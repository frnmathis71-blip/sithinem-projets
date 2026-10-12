const $ = (id) => document.getElementById(id);
function message(text, error = false) { $('message').textContent = text; $('message').classList.toggle('error', error); }
async function action(button, work) {
  button.disabled = true;
  try { await work(); } catch (error) { message(error.message.replace(/^Error invoking remote method '[^']+': Error: /, ''), true); }
  finally { button.disabled = false; }
}
async function printers(selected = $('printer').value) {
  const available = await window.sithinem.printers();
  $('printer').replaceChildren(new Option('Aucune imprimante', ''));
  for (const printer of available) $('printer').add(new Option(printer.displayName || printer.name, printer.name));
  if (selected && !available.some((printer) => printer.name === selected)) $('printer').add(new Option(selected + ' (indisponible)', selected));
  $('printer').value = selected;
}
async function updateStatus() {
  const settings = await window.sithinem.settings();
  $('status').textContent = settings.status;
  $('last-event').textContent = settings.lastEvent;
}
$('settings').addEventListener('submit', (event) => {
  event.preventDefault();
  action(event.submitter, async () => {
    await window.sithinem.save({ siteUrl: $('site').value.trim(), printer: $('printer').value, width: Number($('width').value), token: $('token').value.trim(), printing: $('printing').checked, startup: $('startup').checked });
    $('token').value = '';
    message('Paramètres enregistrés.');
    await updateStatus();
  });
});
$('refresh').onclick = (event) => action(event.target, () => printers());
$('open').onclick = (event) => action(event.target, () => window.sithinem.open());
$('restart').onclick = (event) => action(event.target, async () => { await window.sithinem.restart(); await updateStatus(); });
$('test').onclick = (event) => action(event.target, async () => { await window.sithinem.test(); message('Ticket de test transmis au pilote Windows. Vérifiez sa sortie sur l’imprimante.'); });
(async () => {
  const settings = await window.sithinem.settings();
  $('site').value = settings.siteUrl; $('width').value = String(settings.width);
  $('printing').checked = settings.printing; $('startup').checked = settings.startup;
  if (settings.hasToken) $('token-help').textContent = 'Un jeton est enregistré. Laissez le champ vide pour le conserver. Un changement de site nécessite un nouveau jeton.';
  await printers(settings.printer); await updateStatus();
  setInterval(() => updateStatus().catch(() => {}), 2500);
})().catch((error) => message(error.message, true));
