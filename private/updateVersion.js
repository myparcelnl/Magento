const fs = require('fs');
const path = require('path');

const [, , version] = process.argv;

if (!version.match(/v\d+\.\d+\.\d+(?:-(?:alpha|beta))?/)) {
  throw new Error('File must be called with version as argument.');
}

const rootDir = path.resolve(__dirname, '..');
const parsedVersion = version.replace(/^v/, '');

[
  'composer.json',
  'package.json',
  'etc/module.xml',
].forEach((file) => {
  const filePath = path.resolve(rootDir, file);
  const relativeFilePath = path.relative(rootDir, filePath);
  let contentsAsString;
  let oldVersion;

  if (file.indexOf('.json') !== -1) {
    const contents = require(filePath);

    oldVersion = contents.version;
    contents.version = parsedVersion;

    contentsAsString = JSON.stringify(contents, null, 2);
  }

  if (file.indexOf('.xml') !== -1) {
    contentsAsString = fs.readFileSync(filePath, 'utf8');

    oldVersion = contentsAsString.match(/setup_version="(\d+\.\d+\.\d+(?:-(?:alpha|beta))?)"/)[1];
    contentsAsString = contentsAsString.replace('setup_version="'+oldVersion+'"', 'setup_version="'+parsedVersion+'"');
  }

  fs.writeFile(filePath, contentsAsString, () => {
    console.log(`Changed version from \u{1b}[33m${oldVersion}\u{1b}[0m to \u{1b}[32m${parsedVersion}\u{1b}[0m in ${relativeFilePath}`);
  });
});
