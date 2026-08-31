/**
 * Admin credentials for the scripts that drive the real Filament login.
 *
 * These used to be hardcoded per script, defaulting to a personal admin
 * account and a password that drifted out of date. When the hardcoded value
 * stopped working the fix was to run tmp_set_admin_pwd.php, which overwrote
 * the real admin's password with a test value -- so every run of these tools
 * eventually cost someone their login. Several scripts also tried a list of
 * candidate passwords in turn, which walked straight into Fortify's
 * five-attempts-per-minute throttle.
 *
 * The QA account in .env (AUTOMATED_TESTS_ADMIN_EMAIL / _PASSWORD) is the only
 * source now. Nothing here guesses, and nothing here writes.
 *
 *   const { requireAdminCredentials } = require('../tools/admin-credentials.cjs');
 *   const { email, password } = requireAdminCredentials();
 */

const fs = require('fs');
const path = require('path');

const ENV_PATH = path.resolve(__dirname, '..', '.env');

/**
 * Read one key out of the repo .env.
 *
 * These scripts are run by hand from a shell that has not sourced .env, so the
 * file is parsed directly rather than relying on the environment. Deliberately
 * minimal: enough for `KEY=value` and `KEY="value"`, no interpolation.
 */
function fromEnvFile(key) {
    let contents = '';
    try {
        contents = fs.readFileSync(ENV_PATH, 'utf8');
    } catch (_) {
        return '';
    }
    for (const rawLine of contents.split(/\r?\n/)) {
        const line = rawLine.trim();
        if (!line || line.startsWith('#')) continue;
        const separator = line.indexOf('=');
        if (separator < 0) continue;
        if (line.slice(0, separator).trim() !== key) continue;
        let value = line.slice(separator + 1).trim();
        if ((value.startsWith('"') && value.endsWith('"'))
            || (value.startsWith("'") && value.endsWith("'"))) {
            value = value.slice(1, -1);
        }
        return value;
    }
    return '';
}

function resolve(explicitKey, envFileKey) {
    return String(process.env[explicitKey] || '').trim() || fromEnvFile(envFileKey);
}

/** Credentials, or null when the QA account is not configured. */
function adminCredentials() {
    const email = resolve('ADMIN_EMAIL', 'AUTOMATED_TESTS_ADMIN_EMAIL');
    const password = resolve('ADMIN_PASSWORD', 'AUTOMATED_TESTS_ADMIN_PASSWORD');
    if (!email || !password) return null;
    return { email, password };
}

/** Credentials, or a message explaining exactly what to set. */
function requireAdminCredentials() {
    const credentials = adminCredentials();
    if (credentials) return credentials;
    throw new Error(
        'No admin credentials. Set AUTOMATED_TESTS_ADMIN_EMAIL and '
        + 'AUTOMATED_TESTS_ADMIN_PASSWORD in .env (they point at the QA admin '
        + 'account), or pass ADMIN_EMAIL and ADMIN_PASSWORD in the environment. '
        + 'Do not reset a real admin password to make a script log in.',
    );
}

/**
 * Sign in at the Filament admin login. One attempt, deliberately: guessing a
 * list of passwords is what triggers the login throttle.
 */
async function loginAsAdmin(page, baseUrl) {
    const { email, password } = requireAdminCredentials();
    await page.goto(`${baseUrl}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.fill('#data\\.email', email);
    await page.fill('#data\\.password', password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    try {
        await page.waitForURL((url) => !url.pathname.endsWith('/admin/login'), { timeout: 15000 });
    } catch (_) {
        if (page.url().includes('/admin/login')) {
            throw new Error(`Admin login failed for ${email}. Check AUTOMATED_TESTS_ADMIN_* in .env.`);
        }
    }
}

module.exports = { adminCredentials, requireAdminCredentials, loginAsAdmin, ENV_PATH };
