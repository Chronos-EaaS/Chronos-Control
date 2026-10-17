import { Activity, PageData, User } from './models';

export interface ManagedUser extends User { createdAt?: string | null; lastLoginAt?: string | null; }
export interface SettingValue { key: string; value: string | null; secret: boolean; configured: boolean; }
export interface SettingSection { name: string; revision: string; settings: SettingValue[]; }
export interface CemEnvironment { name: string; nodeCount: number; }
export interface StorageStatus {
  path: string; exists: boolean; readable: boolean; writable: boolean; mounted: boolean | null;
  freeBytes: number | null; totalBytes: number | null; checkedAt: string;
}
export type MaintenanceAction = 'update' | 'rollback' | 'mount' | 'reset-demo' | 'reset-local-systems';
export interface MaintenanceStatus {
  branches: string[]; selectedBranch: string; installedVersion: string; simulationAvailable: boolean;
  executionAvailable: boolean; executionMessage: string; actions: MaintenanceAction[];
}
export interface OperationPlan {
  id: string; action: MaintenanceAction; title: string; branch: string | null; steps: string[];
  confirmation: string; createdAt: string; expiresAt: string; state: 'planned' | 'simulated' | 'completed';
  impact: string; resources?: string[]; unavailableReason?: string | null; signedOut?: boolean;
  canSimulate: boolean; canExecute: boolean; completedAt?: string; output?: string;
}
export interface CemNodeData {
  id: string; hostname: string; environment: string; version: string; os: string; ip: string;
  currentJobId: number | null; cpuPercent: number | null; memoryUsedBytes: number | null;
  memoryTotalBytes: number | null; memoryPercent: number | null; healthStatus: string; warning: boolean;
  lastSeenAt: string | null; missing: boolean;
}
export interface CemNodePage extends PageData<CemNodeData> { missingAfterSeconds: number; }
export interface CemNodeDetail { node: CemNodeData; events: (Activity & { jobId: number | null })[]; }

const number = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1 });
export function formatBytes(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value) || value < 0) return '—';
  if (value === 0) return '0 B';
  const unit = Math.min(Math.floor(Math.log(value) / Math.log(1024)), 5);
  return `${number.format(value / 1024 ** unit)} ${['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'][unit]}`;
}
export function formatPercent(value: number | null): string { return value === null ? 'Unknown' : `${number.format(value)} %`; }
