import { Form, Head, Link, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/hooks/use-locale';
import { useServerPoll } from '@/hooks/use-server-poll';
import { importBatchNeedsPolling } from '@/lib/status-polling';

type Page<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Batch = {
    id: number;
    original_filename: string;
    status: string;
    headers: string[];
    preview: Record<string, string | null>[];
    processed_rows: number;
    valid_rows: number;
    invalid_rows: number;
    commit_requested_at: string | null;
    committed_at: string | null;
    discarded_at: string | null;
    source_unavailable: boolean;
    invalidRows: Page<{
        id: number;
        row_number: number;
        keyword_original: string | null;
        errors: string[];
    }>;
};

export default function Imports({
    activeBatch,
    batches,
    canImport,
    maxBytes,
}: {
    activeBatch: Batch | null;
    batches: Page<Pick<Batch, 'id' | 'original_filename' | 'status'>>;
    canImport: boolean;
    maxBytes: number;
}) {
    const { projectContext } = usePage().props;
    const { t, direction } = useLocale();
    const polling = importBatchNeedsPolling(activeBatch);
    const poll = useServerPoll(['activeBatch', 'batches'], polling);
    if (!projectContext) return null;
    const base = `/projects/${projectContext.project.id}/imports`;
    const statusLabels: Record<string, string> = {
        awaiting_mapping: t('imports.awaitingMapping'),
        queued: t('imports.queued'),
        processing: t('imports.processing'),
        staged: t('imports.staged'),
        committed: t('imports.committed'),
        discarded: t('imports.discarded'),
        failed: t('imports.failed'),
    };
    const pendingCommit =
        activeBatch?.status === 'staged' &&
        activeBatch.commit_requested_at !== null;
    const pages = (page: Page<unknown>) => (
        <nav className="flex gap-4" aria-label={t('imports.pagination')}>
            {page.prev_page_url && (
                <Link href={page.prev_page_url}>{t('home.previous')}</Link>
            )}
            {page.next_page_url && (
                <Link href={page.next_page_url}>{t('home.next')}</Link>
            )}
        </nav>
    );

    return (
        <>
            <Head title={t('imports.title')} />
            <ProjectWorkspaceShell>
                <div
                    className="mx-auto w-full max-w-5xl space-y-6"
                    dir={direction}
                >
                    <h1 className="text-2xl font-semibold">
                        {t('imports.title')}
                    </h1>
                    <p>{t('imports.description')}</p>
                    <Link
                        href={`/projects/${projectContext.project.id}/keywords`}
                    >
                        {t('home.keywords')}
                    </Link>
                    {canImport && (
                        <Form
                            action={base}
                            method="post"
                            encType="multipart/form-data"
                            disableWhileProcessing
                            className="space-y-4 rounded border p-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <Label htmlFor="import-file">
                                        {t('imports.file')}
                                    </Label>
                                    <Input
                                        id="import-file"
                                        name="file"
                                        type="file"
                                        accept=".csv,text/csv"
                                        aria-describedby="import-limit"
                                    />
                                    <p
                                        id="import-limit"
                                        className="text-sm text-muted-foreground"
                                    >
                                        {t('imports.maxBytes')}:{' '}
                                        {maxBytes.toLocaleString()}
                                    </p>
                                    <InputError message={errors.file} />
                                    <Label htmlFor="import-text">
                                        {t('imports.text')}
                                    </Label>
                                    <textarea
                                        id="import-text"
                                        name="text"
                                        rows={6}
                                        maxLength={1048576}
                                        className="w-full rounded border p-3"
                                    />
                                    <InputError message={errors.text} />
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? t('imports.saving')
                                            : t('imports.upload')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                    {!activeBatch ? (
                        <p>{t('imports.empty')}</p>
                    ) : (
                        <section
                            className="space-y-4 rounded border p-4"
                            aria-labelledby="batch-title"
                        >
                            <h2
                                id="batch-title"
                                className="text-lg font-semibold"
                            >
                                {activeBatch.original_filename}
                            </h2>
                            <div role="status" aria-live="polite">
                                <p>
                                    {pendingCommit
                                        ? t('imports.waitingCommit')
                                        : (statusLabels[activeBatch.status] ??
                                          t('imports.failed'))}
                                </p>
                                {activeBatch.status === 'queued' && (
                                    <p>{t('imports.waitingWorker')}</p>
                                )}
                                {activeBatch.status === 'processing' && (
                                    <p>{t('imports.processingHint')}</p>
                                )}
                                <p>
                                    {t('imports.processed')}:{' '}
                                    {activeBatch.processed_rows} ·{' '}
                                    {t('imports.valid')}:{' '}
                                    {activeBatch.valid_rows} ·{' '}
                                    {t('imports.invalid')}:{' '}
                                    {activeBatch.invalid_rows}
                                </p>
                            </div>
                            {(activeBatch.status === 'failed' ||
                                activeBatch.source_unavailable) && (
                                <p role="alert">
                                    {t('imports.failureMessage')}
                                </p>
                            )}
                            {polling && (
                                <p
                                    className="text-sm text-muted-foreground"
                                    data-test="import-auto-refresh"
                                >
                                    {t('imports.autoRefresh')}
                                </p>
                            )}
                            {polling && poll.failed && (
                                <p role="alert">{t('imports.refreshError')}</p>
                            )}
                            {activeBatch.preview.length > 0 && (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-start text-sm">
                                        <caption className="py-2 text-start">
                                            {t('imports.preview')}
                                        </caption>
                                        <thead>
                                            <tr>
                                                {activeBatch.headers.map(
                                                    (header) => (
                                                        <th
                                                            key={header}
                                                            scope="col"
                                                            className="p-2 text-start"
                                                        >
                                                            {header}
                                                        </th>
                                                    ),
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {activeBatch.preview.map(
                                                (row, index) => (
                                                    <tr key={index}>
                                                        {activeBatch.headers.map(
                                                            (header) => (
                                                                <td
                                                                    key={header}
                                                                    className="border-t p-2"
                                                                >
                                                                    {
                                                                        row[
                                                                            header
                                                                        ]
                                                                    }
                                                                </td>
                                                            ),
                                                        )}
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                            {canImport &&
                                activeBatch.status === 'awaiting_mapping' &&
                                !activeBatch.source_unavailable && (
                                    <Form
                                        action={`${base}/${activeBatch.id}/mapping`}
                                        method="post"
                                        disableWhileProcessing
                                        className="space-y-3"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <Label htmlFor="keyword-column">
                                                    {t('imports.keywordColumn')}
                                                </Label>
                                                <select
                                                    id="keyword-column"
                                                    name="keyword"
                                                    required
                                                    className="w-full rounded border p-2"
                                                    defaultValue={
                                                        activeBatch.headers[0]
                                                    }
                                                >
                                                    {activeBatch.headers.map(
                                                        (header) => (
                                                            <option
                                                                key={header}
                                                                value={header}
                                                            >
                                                                {header}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                                <InputError
                                                    message={errors.keyword}
                                                />
                                                <Label htmlFor="volume-column">
                                                    {t('imports.volumeColumn')}
                                                </Label>
                                                <select
                                                    id="volume-column"
                                                    name="volume"
                                                    className="w-full rounded border p-2"
                                                    defaultValue=""
                                                >
                                                    <option value="">
                                                        {t('imports.noVolume')}
                                                    </option>
                                                    {activeBatch.headers.map(
                                                        (header) => (
                                                            <option
                                                                key={header}
                                                                value={header}
                                                            >
                                                                {header}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                                <InputError
                                                    message={errors.volume}
                                                />
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    {t('imports.process')}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            {activeBatch.invalidRows.data.length > 0 && (
                                <section className="space-y-3">
                                    <h3>{t('imports.invalidRows')}</h3>
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr>
                                                <th scope="col">
                                                    {t('imports.row')}
                                                </th>
                                                <th scope="col">
                                                    {t('imports.keywordColumn')}
                                                </th>
                                                <th scope="col">
                                                    {t('imports.reason')}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {activeBatch.invalidRows.data.map(
                                                (row) => (
                                                    <tr key={row.id}>
                                                        <td className="p-2">
                                                            {row.row_number}
                                                        </td>
                                                        <td>
                                                            {row.keyword_original ??
                                                                '—'}
                                                        </td>
                                                        <td>
                                                            {row.errors
                                                                .map((error) =>
                                                                    error ===
                                                                    'volume_not_numeric'
                                                                        ? t(
                                                                              'imports.invalidVolume',
                                                                          )
                                                                        : t(
                                                                              'imports.invalidKeyword',
                                                                          ),
                                                                )
                                                                .join(' · ')}
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                    {pages(activeBatch.invalidRows)}
                                </section>
                            )}
                            {canImport &&
                                activeBatch.status === 'staged' &&
                                !pendingCommit && (
                                    <Form
                                        action={`${base}/${activeBatch.id}/commit`}
                                        method="post"
                                        disableWhileProcessing
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {t('imports.commit')}
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            {canImport &&
                                [
                                    'awaiting_mapping',
                                    'staged',
                                    'failed',
                                ].includes(activeBatch.status) &&
                                activeBatch.commit_requested_at === null && (
                                    <Form
                                        action={`${base}/${activeBatch.id}/discard`}
                                        method="post"
                                        disableWhileProcessing
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <InputError
                                                    message={errors.file}
                                                />
                                                <Button
                                                    type="submit"
                                                    variant="destructive"
                                                    disabled={processing}
                                                >
                                                    {t('imports.discard')}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            {activeBatch.committed_at && (
                                <p>
                                    {t('imports.committedAt')}:{' '}
                                    <time dateTime={activeBatch.committed_at}>
                                        {activeBatch.committed_at}
                                    </time>
                                </p>
                            )}
                        </section>
                    )}
                    {batches.data.length > 0 && (
                        <section className="space-y-3">
                            <h2 className="font-semibold">
                                {t('imports.history')}
                            </h2>
                            <ul className="space-y-2">
                                {batches.data.map((batch) => (
                                    <li key={batch.id}>
                                        <Link
                                            href={`${base}?batch=${batch.id}`}
                                        >
                                            {batch.original_filename}
                                        </Link>{' '}
                                        ·{' '}
                                        {statusLabels[batch.status] ??
                                            t('imports.failed')}
                                    </li>
                                ))}
                            </ul>
                            {pages(batches)}
                        </section>
                    )}
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
