import { TestBed, ComponentFixture } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { JobLogAnalysis } from './job-log-analysis';

const finding = {
  detectorVersion: 'signatures-v1',
  epoch: 'generation-a',
  rule: 'timeout',
  severity: 'error',
  reason: 'The record matches a timeout or deadline diagnostic.',
  byteStart: 0,
  byteEnd: 40,
  excerpt: '[ERROR] Request timed out <fixture>',
};
const first = {
  detectorVersion: 'signatures-v1',
  epoch: 'generation-a',
  available: true,
  reset: false,
  offset: 0,
  nextOffset: 40,
  size: 56,
  hasMore: true,
  pendingLine: false,
  skippedLongLines: 0,
  findings: [finding],
  startCursor: 'first-start',
  cursor: 'first-next',
};
const second = {
  ...first, offset: 40, nextOffset: 56, hasMore: false, findings: [],
  startCursor: 'second-start', cursor: 'second-next',
};
const empty = {
  ...second, offset: 56, nextOffset: 56, startCursor: 'empty-start', cursor: 'empty-next',
};

describe('Job log analysis', () => {
  let fixture: ComponentFixture<JobLogAnalysis>;
  let api: { get: ReturnType<typeof vi.fn> };

  beforeEach(() => {
    api = { get: vi.fn() };
    TestBed.configureTestingModule({
      imports: [JobLogAnalysis],
      providers: [{ provide: Api, useValue: api }],
    });
    fixture = TestBed.createComponent(JobLogAnalysis);
    fixture.componentRef.setInput('jobId', 11);
    fixture.detectChanges();
  });

  async function click(label: string): Promise<void> {
    const buttons = Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('button'));
    buttons.find(button => button.textContent?.trim() === label)!.click();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  it('scans only on demand and explains each finding using plain text and raw offsets', async () => {
    expect(api.get).not.toHaveBeenCalled();
    api.get.mockResolvedValueOnce(first);
    await click('Scan log');
    expect(api.get).toHaveBeenCalledWith('/jobs/11/log/analysis', {});
    const root = fixture.nativeElement as HTMLElement;
    expect(root.textContent).toContain('signatures-v1');
    expect(root.textContent).toContain('Bytes [0, 40)');
    expect(root.textContent).toContain(finding.reason);
    expect(root.querySelector('code')?.textContent).toBe(finding.excerpt);
    expect(root.querySelector('fixture')).toBeNull();
  });

  it('continues, checks for new output without adding a duplicate page, and revisits an earlier range', async () => {
    api.get.mockResolvedValueOnce(first).mockResolvedValueOnce(second).mockResolvedValueOnce(empty).mockResolvedValueOnce(first);
    await click('Scan log');
    await click('Next page');
    expect(api.get).toHaveBeenLastCalledWith('/jobs/11/log/analysis', { cursor: 'first-next' });
    await click('Check for new output');
    expect(api.get).toHaveBeenLastCalledWith('/jobs/11/log/analysis', { cursor: 'second-next' });
    expect(fixture.nativeElement.textContent).toContain('No new complete lines');
    await click('Previous page');
    expect(api.get).toHaveBeenLastCalledWith('/jobs/11/log/analysis', { cursor: 'first-start' });
    expect(fixture.nativeElement.textContent).toContain('Bytes [0, 40)');
    expect(fixture.componentInstance.hasPrevious()).toBe(false);
  });

  it('restarts at zero with an explanation when the server reports a reset', async () => {
    const replacement = {
      ...first, epoch: 'generation-b', reset: true, findings: [],
      nextOffset: 0, size: 8, hasMore: false, pendingLine: true,
    };
    api.get.mockResolvedValueOnce(first).mockResolvedValueOnce(second).mockResolvedValueOnce(replacement);
    await click('Scan log');
    await click('Next page');
    await click('Check for new output');
    expect(fixture.componentInstance.hasPrevious()).toBe(false);
    expect(fixture.nativeElement.textContent).toContain('restarted from byte 0');
    expect(fixture.nativeElement.textContent).toContain('ends with a newline');
  });

  it('ignores a pending response when the job changes and permits retry after an error', async () => {
    let resolve!: (value: typeof first) => void;
    api.get.mockReturnValueOnce(new Promise(res => { resolve = res; }));
    const oldScan = fixture.componentInstance.scan('start');
    fixture.componentRef.setInput('jobId', 12);
    fixture.detectChanges();
    resolve(first);
    await oldScan;
    expect(fixture.componentInstance.page()).toBeNull();
    api.get.mockRejectedValueOnce(new Error('Please retry')).mockResolvedValueOnce(first);
    await click('Scan log');
    expect(fixture.nativeElement.querySelector('[role="alert"]')?.textContent).toContain('Please retry');
    await click('Scan log');
    expect(api.get).toHaveBeenLastCalledWith('/jobs/12/log/analysis', {});
    expect(fixture.nativeElement.querySelector('[role="alert"]')).toBeNull();
  });
});
