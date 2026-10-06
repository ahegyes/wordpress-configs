// Smoke fixture for node/tsconfig.base.json — proves it loads and that strict mode is applied.
export const configSmoke: number = 1;

// @ts-expect-error only compiles under strictNullChecks; dropping `strict` from the base fails the smoke.
export const strictProbe: string = null;
