// Copies the built installer and its update manifest to the site's public folder,
// where installed applications look for new versions: <site>/logiciel/mises-a-jour/latest.yml
const fs = require('node:fs');
const path = require('node:path');
const { version } = require('../package.json');

const release = path.join(__dirname, '..', 'release');
const target = path.join(__dirname, '..', '..', 'public', 'logiciel', 'mises-a-jour');
const installer = `Sithinem-Setup-${version}.exe`;
const files = ['latest.yml', installer, installer + '.blockmap'];

for (const file of files) {
  if (!fs.existsSync(path.join(release, file))) {
    console.error(`Fichier manquant : release/${file}. Lancez d’abord « npm run dist ».`);
    process.exit(1);
  }
}
const manifest = fs.readFileSync(path.join(release, 'latest.yml'), 'utf8');
if (!manifest.includes(`version: ${version}`)) {
  console.error('latest.yml ne correspond pas à la version ' + version + '. Reconstruisez avec « npm run dist ».');
  process.exit(1);
}
fs.mkdirSync(target, { recursive: true });
// Keep only the current version: older installers are not needed for updates.
for (const old of fs.readdirSync(target)) if (!files.includes(old)) fs.rmSync(path.join(target, old));
// Installer first, manifest last, so a client never sees a manifest pointing to a missing file.
for (const file of [installer, installer + '.blockmap', 'latest.yml']) fs.copyFileSync(path.join(release, file), path.join(target, file));
console.log(`Version ${version} prête dans public/logiciel/mises-a-jour. Déployez ce dossier sur le serveur.`);
