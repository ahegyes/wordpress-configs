// Smoke fixture for node/tsconfig.base.json — proves it loads and that strict mode is applied.
export const dwsConfigSmoke: number = 1;

// @ts-expect-error only compiles under strictNullChecks; dropping `strict` from the base fails the smoke.
export const dwsStrictProbe: string = null;
