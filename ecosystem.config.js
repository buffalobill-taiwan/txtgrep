const port = Number(process.env.TXTGREP_PORT || 8083);
const phpBin = process.env.PHP_BIN || 'php';
const appName = process.env.TXTGREP_APP_NAME || 'txtgrep';

module.exports = {
  apps: [
    {
      name: appName,
      cwd: __dirname,
      script: phpBin,
      interpreter: 'none',
      args: [
        '-d', 'display_errors=0',
        '-d', 'log_errors=1',
        '-S', `127.0.0.1:${port}`,
        '-t', 'public',
      ],
      env: {
        TXTGREP_PORT: String(port),
      },
    },
  ],
};
