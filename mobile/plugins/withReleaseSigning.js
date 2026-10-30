/**
 * Signs the Android RELEASE build with our own key instead of the debug key that Expo generates by default.
 * The key file and its passwords are NOT in the project: they come from the user-level Gradle file
 * (~/.gradle/gradle.properties: EZEXAM_STORE_FILE, EZEXAM_STORE_PASSWORD, EZEXAM_KEY_ALIAS, EZEXAM_KEY_PASSWORD).
 * If they are missing, the release build stops with a clear message instead of silently using the debug key.
 */
const { withAppBuildGradle } = require('expo/config-plugins');

module.exports = function withReleaseSigning(config) {
  return withAppBuildGradle(config, (c) => {
    // On Expo's cloud builder (EAS) the signing key is injected by EAS itself, so leave the file alone.
    if (process.env.EAS_BUILD) return c;
    let g = c.modResults.contents;
    if (g.includes('EZEXAM_STORE_FILE')) return c;

    g = g.replace(
      /signingConfigs \{\s*debug \{/,
      `signingConfigs {
        release {
            if (!project.hasProperty('EZEXAM_STORE_FILE')) {
                throw new GradleException('Release signing key not configured: set EZEXAM_STORE_FILE, EZEXAM_STORE_PASSWORD, EZEXAM_KEY_ALIAS and EZEXAM_KEY_PASSWORD in ~/.gradle/gradle.properties')
            }
            storeFile file(project.property('EZEXAM_STORE_FILE'))
            storePassword project.property('EZEXAM_STORE_PASSWORD')
            keyAlias project.property('EZEXAM_KEY_ALIAS')
            keyPassword project.property('EZEXAM_KEY_PASSWORD')
        }
        debug {`
    );
    // Only the release build type uses it (the debug build type keeps the debug key).
    g = g.replace(/(release \{\s*\/\/ Caution![\s\S]*?\n\s*)signingConfig signingConfigs\.debug/, '$1signingConfig signingConfigs.release');
    c.modResults.contents = g;
    return c;
  });
};
