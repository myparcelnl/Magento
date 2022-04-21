const mainConfig = require('@myparcel/semantic-release-config');
const {addExecPlugin, addGitHubPlugin, addGitPlugin} = require(
  '@myparcel/semantic-release-config/src/plugins',
);

module.exports = {
  extends: '@myparcel/semantic-release-config',
  plugins: [
    ...mainConfig.plugins,
    addGitHubPlugin(),
    addExecPlugin({
      prepareCmd: 'node ./private/updateVersion.js ${nextRelease.version} && npm run build',
    }),
    addGitPlugin(),
  ],
};
