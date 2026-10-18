export interface User {
  id: number;
  username: string;
  firstName: string;
  lastName: string;
  email: string;
  gender: number;
  role: number;
  roleName: string;
  active: boolean;
  activated: boolean;
}

export interface SessionData {
  user: User | null;
  csrfToken: string;
  impersonation: { active: boolean; originalUser: User | null };
}

export interface Metadata {
  api: 'ui';
  applicationVersion: string;
  uiBuildId: string | null;
  timezone: string;
  password: { minLength: number; maxLength: number; requireComplexity: boolean };
  features?: { remoteRepositories: boolean };
}

export interface PageData<T> { items: T[]; total: number; page: number; pageSize: number; }
export interface Permissions { canEdit: boolean; canArchive?: boolean; canManageMembers?: boolean; canRun?: boolean; }

export interface Project {
  id: number; name: string; description: string; ownerId: number; systemId: number;
  systemName: string; archived: boolean; finished: boolean; environment: string;
  permissions: Permissions;
}

export interface ChronosSystem {
  id: number; name: string; description: string; ownerId: number; archived: boolean;
  cem: boolean; identifier: string; permissions: Permissions;
}

export interface Activity {
  id: number; title: string; text: string; at: string; actorName?: string;
  resourceType?: string; resourceId?: number | string;
  resources?: { type: string; id: number | string; label: string }[];
}

export interface DashboardData {
  counts: {
    projects: number; experiments: number; evaluationsCompleted: number;
    evaluationsRunning: number; jobs: number; activeJobs: number; failedJobs: number;
  };
  events: Activity[];
}
