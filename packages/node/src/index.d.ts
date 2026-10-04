import type { IncomingMessage, ServerResponse } from 'node:http';

export type Dimension = 'country_collection' | 'referrer_collection' | 'browser_collection' | 'device_collection' | 'os_collection';
export type Options = { dataDirectory?: string; databasePath?: string; retentionDays?: 30 | 90 | 180 | 365; pathExclusions?: string[]; botPatterns?: string[] };
export type RequestContext = { path: string; userAgent?: string; country?: string; referrer?: string };
export type AuditReport = { application: string; contract_version: number; schema_version: number; profile: 'strict' | 'extended'; fingerprint: string; configuration: Record<string, unknown>; checks: Record<string, boolean>; result: 'PASS' | 'FAIL' };

export class Barelytics {
  constructor(options?: Options);
  readonly databasePath: string;
  normalizePath(path: string): string | null;
  trackPageView(context: RequestContext): boolean;
  updatePrivacy(values: Partial<Record<Dimension, boolean>>, confirmation?: string): string;
  returnToStrictMode(): void;
  setRetention(days: number): void;
  dashboard(periodDays?: number): { total: number; byDay: { day: string; views: number }[]; byPage: { path: string; views: number }[]; retentionDays: number; profile: string };
  audit(): AuditReport;
  cleanup(limit?: number): boolean;
  close(): void;
}

export function createTrackHandler(analytics: Barelytics): (request: IncomingMessage, response: ServerResponse) => void;
export function createAdminHandler(analytics: Barelytics, options: { authorize(request: IncomingMessage): boolean; verifyCsrf(request: IncomingMessage, token: string): boolean }): (request: IncomingMessage, response: ServerResponse) => Promise<void>;
export function normalizePath(path: string): string | null;
export function isBot(userAgent: string, extraPatterns?: string[]): boolean;
