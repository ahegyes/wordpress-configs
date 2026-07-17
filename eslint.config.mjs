// Dogfood the shared baseline this package exports, so this repo's own JS is held to the
// same rules its consumers get. Its no-console allowance covers smoke.mjs's CLI output.
import base from './node/eslint.config.base.mjs';

export default base;
