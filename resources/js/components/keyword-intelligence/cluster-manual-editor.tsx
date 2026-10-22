import { router } from '@inertiajs/react';
import { GitMerge, MoveRight, Scissors, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useLocale } from '@/hooks/use-locale';
import type { IntelligenceCluster } from '@/types/keyword-intelligence';

type ClusterSearchResult = {
    id: number;
    name: string;
    primary_keyword_id: number;
    primary_keyword: string | null;
    member_count: number;
    is_locked: boolean;
};

type MemberSearchResult = {
    id: number;
    keyword_id: number;
    keyword_cluster_id: number;
    display_text: string | null;
    is_primary: boolean;
};

type Copy = {
    title: string;
    description: string;
    lockedHint: string;
    merge: string;
    mergeDescription: string;
    move: string;
    moveDescription: string;
    split: string;
    splitDescription: string;
    searchClusters: string;
    searchMembers: string;
    search: string;
    searching: string;
    noResults: string;
    selected: string;
    primary: string;
    members: string;
    locked: string;
    mergeIntoCurrent: string;
    movingMember: string;
    targetCluster: string;
    replacementPrimary: string;
    replacementHint: string;
    moveMember: string;
    splitName: string;
    splitNamePlaceholder: string;
    splitMembers: string;
    splitPrimary: string;
    splitReplacementHint: string;
    createSplit: string;
    choose: string;
    remove: string;
    actionFailed: string;
};

const copies: Record<'fa' | 'en', Copy> = {
    fa: {
        title: 'ویرایش دستی خوشه',
        description:
            'ادغام، انتقال عضو و جداسازی را با حفظ تاریخچه و کنترل‌های ایمنی انجام دهید.',
        lockedHint: 'برای تغییر اعضا ابتدا قفل خوشه را باز کنید.',
        merge: 'ادغام در این خوشه',
        mergeDescription:
            'یک خوشه دیگر را انتخاب کنید تا اعضای آن به همین خوشه منتقل و هویت قبلی آن بایگانی شود.',
        move: 'انتقال یک عضو',
        moveDescription:
            'یک کلمه را به خوشه دیگری منتقل کنید. انتقال کلمه اصلی نیازمند جایگزین صریح است.',
        split: 'جدا کردن اعضا',
        splitDescription:
            'چند عضو را به یک خوشه جدید ببرید و کلمه اصلی خوشه جدید را صریح انتخاب کنید.',
        searchClusters: 'نام خوشه یا کلمه اصلی',
        searchMembers: 'جستجوی اعضای این خوشه',
        search: 'جستجو',
        searching: 'در حال جستجو…',
        noResults: 'نتیجه‌ای پیدا نشد.',
        selected: 'انتخاب‌شده',
        primary: 'اصلی',
        members: 'عضو',
        locked: 'قفل',
        mergeIntoCurrent: 'ادغام کن',
        movingMember: 'عضو برای انتقال',
        targetCluster: 'خوشه مقصد',
        replacementPrimary: 'جایگزین کلمه اصلی',
        replacementHint:
            'این عضو کلمه اصلی است؛ یکی از اعضای باقی‌مانده را به‌عنوان اصلی جایگزین انتخاب کنید.',
        moveMember: 'انتقال عضو',
        splitName: 'نام خوشه جدید',
        splitNamePlaceholder: 'مثلاً کفش ورزشی زنانه',
        splitMembers: 'اعضای خوشه جدید',
        splitPrimary: 'کلمه اصلی خوشه جدید',
        splitReplacementHint:
            'کلمه اصلی فعلی در حال جدا شدن است؛ برای خوشه مبدا یک کلمه اصلی جایگزین انتخاب کنید.',
        createSplit: 'ساخت خوشه جداشده',
        choose: 'انتخاب',
        remove: 'حذف',
        actionFailed:
            'عملیات انجام نشد. داده‌ها را تازه کنید و دوباره تلاش کنید.',
    },
    en: {
        title: 'Manual cluster editing',
        description:
            'Merge, move, and split members while preserving history and safety checks.',
        lockedHint: 'Unlock this cluster before changing memberships.',
        merge: 'Merge into this cluster',
        mergeDescription:
            'Choose another cluster to move its members here and archive its previous identity.',
        move: 'Move one member',
        moveDescription:
            'Move a keyword to another cluster. Moving the primary keyword requires an explicit replacement.',
        split: 'Split members',
        splitDescription:
            'Move selected members into a new cluster and explicitly choose its primary keyword.',
        searchClusters: 'Cluster name or primary keyword',
        searchMembers: 'Search members of this cluster',
        search: 'Search',
        searching: 'Searching…',
        noResults: 'No results found.',
        selected: 'Selected',
        primary: 'Primary',
        members: 'members',
        locked: 'Locked',
        mergeIntoCurrent: 'Merge',
        movingMember: 'Member to move',
        targetCluster: 'Target cluster',
        replacementPrimary: 'Replacement primary',
        replacementHint:
            'This member is the primary keyword; choose a remaining member as the explicit replacement.',
        moveMember: 'Move member',
        splitName: 'New cluster name',
        splitNamePlaceholder: 'e.g. Women running shoes',
        splitMembers: 'New cluster members',
        splitPrimary: 'New cluster primary',
        splitReplacementHint:
            'The current primary is being split out; choose a replacement primary for the source cluster.',
        createSplit: 'Create split cluster',
        choose: 'Choose',
        remove: 'Remove',
        actionFailed: 'The action failed. Refresh the data and try again.',
    },
};

async function fetchData<T>(url: string): Promise<T[]> {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    if (!response.ok) throw new Error('request failed');
    const payload = (await response.json()) as { data?: T[] };

    return payload.data ?? [];
}

function ResultButton({
    label,
    meta,
    onClick,
    disabled = false,
}: {
    label: string;
    meta: string;
    onClick: () => void;
    disabled?: boolean;
}) {
    return (
        <button
            type="button"
            className="flex w-full items-center justify-between gap-3 rounded-md px-2 py-2 text-start text-xs hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
            onClick={onClick}
            disabled={disabled}
        >
            <span className="min-w-0 truncate font-medium">{label}</span>
            <span className="shrink-0 text-muted-foreground">{meta}</span>
        </button>
    );
}

export function ClusterManualEditor({
    cluster,
    projectId,
    canManage,
}: {
    cluster: IntelligenceCluster;
    projectId: number;
    canManage: boolean;
}) {
    const { locale } = useLocale();
    const copy = copies[locale];
    const membershipDisabled = !canManage || cluster.is_locked;
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const [mergeQuery, setMergeQuery] = useState('');
    const [mergeResults, setMergeResults] = useState<ClusterSearchResult[]>([]);
    const [mergeSource, setMergeSource] = useState<ClusterSearchResult | null>(
        null,
    );
    const [mergeSearching, setMergeSearching] = useState(false);

    const [memberQuery, setMemberQuery] = useState('');
    const [memberResults, setMemberResults] = useState<MemberSearchResult[]>(
        [],
    );
    const [memberSearching, setMemberSearching] = useState(false);
    const [moveMember, setMoveMember] = useState<MemberSearchResult | null>(
        null,
    );
    const [moveReplacement, setMoveReplacement] =
        useState<MemberSearchResult | null>(null);

    const [targetQuery, setTargetQuery] = useState('');
    const [targetResults, setTargetResults] = useState<ClusterSearchResult[]>(
        [],
    );
    const [moveTarget, setMoveTarget] = useState<ClusterSearchResult | null>(
        null,
    );
    const [targetSearching, setTargetSearching] = useState(false);

    const [splitName, setSplitName] = useState('');
    const [splitMembers, setSplitMembers] = useState<MemberSearchResult[]>([]);
    const [splitPrimaryId, setSplitPrimaryId] = useState<number | null>(null);
    const [splitReplacement, setSplitReplacement] =
        useState<MemberSearchResult | null>(null);

    const availableMemberResults = useMemo(
        () =>
            memberResults.filter(
                (member) =>
                    !splitMembers.some(
                        (selected) => selected.keyword_id === member.keyword_id,
                    ),
            ),
        [memberResults, splitMembers],
    );
    const splitContainsCurrentPrimary = splitMembers.some(
        (member) => member.is_primary,
    );

    const searchClusters = async (
        query: string,
        setter: (value: ClusterSearchResult[]) => void,
        setSearching: (value: boolean) => void,
    ) => {
        setSearching(true);
        setError(null);
        try {
            const data = await fetchData<ClusterSearchResult>(
                `/projects/${projectId}/keyword-intelligence/clusters/search?q=${encodeURIComponent(query)}`,
            );
            setter(data.filter((item) => item.id !== cluster.id));
        } catch {
            setError(copy.actionFailed);
        } finally {
            setSearching(false);
        }
    };

    const searchMembers = async () => {
        setMemberSearching(true);
        setError(null);
        try {
            setMemberResults(
                await fetchData<MemberSearchResult>(
                    `/projects/${projectId}/keyword-intelligence/clusters/${cluster.id}/members/search?q=${encodeURIComponent(memberQuery)}`,
                ),
            );
        } catch {
            setError(copy.actionFailed);
        } finally {
            setMemberSearching(false);
        }
    };

    const mutationOptions = {
        preserveScroll: true,
        onError: () => setError(copy.actionFailed),
        onFinish: () => setBusy(false),
    };

    const merge = () => {
        if (!mergeSource || membershipDisabled) return;
        setBusy(true);
        setError(null);
        router.post(
            `/projects/${projectId}/keyword-intelligence/clusters/${cluster.id}/merge`,
            { source_cluster_ids: [mergeSource.id] },
            mutationOptions,
        );
    };

    const move = () => {
        if (!moveMember || !moveTarget || membershipDisabled) return;
        if (moveMember.is_primary && !moveReplacement) return;
        setBusy(true);
        setError(null);
        router.post(
            `/projects/${projectId}/keyword-intelligence/clusters/${cluster.id}/members/${moveMember.keyword_id}/move`,
            {
                target_cluster_id: moveTarget.id,
                replacement_primary_keyword_id: moveMember.is_primary
                    ? moveReplacement?.keyword_id
                    : null,
            },
            mutationOptions,
        );
    };

    const split = () => {
        if (
            membershipDisabled ||
            splitMembers.length === 0 ||
            !splitPrimaryId ||
            splitName.trim() === '' ||
            (splitContainsCurrentPrimary && !splitReplacement)
        ) {
            return;
        }
        setBusy(true);
        setError(null);
        router.post(
            `/projects/${projectId}/keyword-intelligence/clusters/${cluster.id}/split`,
            {
                member_ids: splitMembers.map((member) => member.keyword_id),
                name: splitName.trim(),
                primary_keyword_id: splitPrimaryId,
                replacement_primary_keyword_id: splitContainsCurrentPrimary
                    ? splitReplacement?.keyword_id
                    : null,
            },
            mutationOptions,
        );
    };

    const toggleSplitMember = (member: MemberSearchResult) => {
        const exists = splitMembers.some(
            (selected) => selected.keyword_id === member.keyword_id,
        );
        if (exists) {
            setSplitMembers((current) =>
                current.filter(
                    (selected) => selected.keyword_id !== member.keyword_id,
                ),
            );
            if (splitPrimaryId === member.keyword_id) setSplitPrimaryId(null);
            return;
        }
        setSplitMembers((current) => [...current, member]);
    };

    return (
        <details className="mt-4 rounded-lg border bg-muted/15 p-3">
            <summary className="cursor-pointer text-sm font-medium select-none">
                {copy.title}
            </summary>
            <p className="mt-2 text-xs text-muted-foreground">
                {copy.description}
            </p>
            {cluster.is_locked && (
                <p className="mt-2 rounded-md border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-xs">
                    {copy.lockedHint}
                </p>
            )}

            <div className="mt-4 grid gap-4 2xl:grid-cols-3">
                <section className="rounded-lg border bg-background p-3">
                    <div className="flex items-center gap-2 text-sm font-medium">
                        <GitMerge className="size-4" />
                        {copy.merge}
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {copy.mergeDescription}
                    </p>
                    <div className="mt-3 flex gap-2">
                        <input
                            className="h-9 min-w-0 flex-1 rounded-md border bg-background px-3 text-xs"
                            value={mergeQuery}
                            onChange={(event) =>
                                setMergeQuery(event.target.value)
                            }
                            placeholder={copy.searchClusters}
                            disabled={membershipDisabled || busy}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    void searchClusters(
                                        mergeQuery,
                                        setMergeResults,
                                        setMergeSearching,
                                    );
                                }
                            }}
                        />
                        <button
                            type="button"
                            className="inline-flex h-9 items-center gap-1 rounded-md border px-2 text-xs disabled:opacity-50"
                            onClick={() =>
                                void searchClusters(
                                    mergeQuery,
                                    setMergeResults,
                                    setMergeSearching,
                                )
                            }
                            disabled={
                                membershipDisabled || busy || mergeSearching
                            }
                        >
                            <Search className="size-3.5" />
                            {mergeSearching ? copy.searching : copy.search}
                        </button>
                    </div>
                    {mergeResults.length > 0 && (
                        <div className="mt-2 max-h-40 overflow-auto rounded-md border p-1">
                            {mergeResults.map((result) => (
                                <ResultButton
                                    key={result.id}
                                    label={result.name}
                                    meta={`${result.member_count} ${copy.members}${result.is_locked ? ` · ${copy.locked}` : ''}`}
                                    onClick={() => setMergeSource(result)}
                                    disabled={result.is_locked}
                                />
                            ))}
                        </div>
                    )}
                    {mergeSource && (
                        <div className="mt-2 rounded-md bg-muted/50 p-2 text-xs">
                            {copy.selected}: <strong>{mergeSource.name}</strong>
                        </div>
                    )}
                    <button
                        type="button"
                        className="mt-3 inline-flex h-9 items-center rounded-md border border-primary bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-50"
                        onClick={merge}
                        disabled={
                            membershipDisabled ||
                            busy ||
                            !mergeSource ||
                            mergeSource.is_locked
                        }
                    >
                        {copy.mergeIntoCurrent}
                    </button>
                </section>

                <section className="rounded-lg border bg-background p-3">
                    <div className="flex items-center gap-2 text-sm font-medium">
                        <MoveRight className="size-4" />
                        {copy.move}
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {copy.moveDescription}
                    </p>
                    <div className="mt-3 flex gap-2">
                        <input
                            className="h-9 min-w-0 flex-1 rounded-md border bg-background px-3 text-xs"
                            value={memberQuery}
                            onChange={(event) =>
                                setMemberQuery(event.target.value)
                            }
                            placeholder={copy.searchMembers}
                            disabled={membershipDisabled || busy}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    void searchMembers();
                                }
                            }}
                        />
                        <button
                            type="button"
                            className="inline-flex h-9 items-center gap-1 rounded-md border px-2 text-xs disabled:opacity-50"
                            onClick={() => void searchMembers()}
                            disabled={
                                membershipDisabled || busy || memberSearching
                            }
                        >
                            <Search className="size-3.5" />
                            {memberSearching ? copy.searching : copy.search}
                        </button>
                    </div>
                    {memberResults.length > 0 && (
                        <div className="mt-2 max-h-40 overflow-auto rounded-md border p-1">
                            {memberResults.map((member) => (
                                <ResultButton
                                    key={member.id}
                                    label={
                                        member.display_text ??
                                        `#${member.keyword_id}`
                                    }
                                    meta={
                                        member.is_primary
                                            ? copy.primary
                                            : copy.choose
                                    }
                                    onClick={() => {
                                        setMoveMember(member);
                                        setMoveReplacement(null);
                                    }}
                                />
                            ))}
                        </div>
                    )}
                    {moveMember && (
                        <div className="mt-2 rounded-md bg-muted/50 p-2 text-xs">
                            {copy.movingMember}:{' '}
                            <strong>
                                {moveMember.display_text ??
                                    `#${moveMember.keyword_id}`}
                            </strong>
                        </div>
                    )}
                    {moveMember?.is_primary && (
                        <div className="mt-2 rounded-md border border-amber-500/30 p-2">
                            <p className="text-xs text-muted-foreground">
                                {copy.replacementHint}
                            </p>
                            <div className="mt-2 space-y-1">
                                {memberResults
                                    .filter(
                                        (member) =>
                                            member.keyword_id !==
                                            moveMember.keyword_id,
                                    )
                                    .map((member) => (
                                        <ResultButton
                                            key={`replacement-${member.id}`}
                                            label={
                                                member.display_text ??
                                                `#${member.keyword_id}`
                                            }
                                            meta={copy.replacementPrimary}
                                            onClick={() =>
                                                setMoveReplacement(member)
                                            }
                                        />
                                    ))}
                            </div>
                            {moveReplacement && (
                                <p className="mt-2 text-xs">
                                    {copy.selected}:{' '}
                                    <strong>
                                        {moveReplacement.display_text ??
                                            `#${moveReplacement.keyword_id}`}
                                    </strong>
                                </p>
                            )}
                        </div>
                    )}
                    <div className="mt-3 flex gap-2">
                        <input
                            className="h-9 min-w-0 flex-1 rounded-md border bg-background px-3 text-xs"
                            value={targetQuery}
                            onChange={(event) =>
                                setTargetQuery(event.target.value)
                            }
                            placeholder={copy.searchClusters}
                            disabled={membershipDisabled || busy}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    void searchClusters(
                                        targetQuery,
                                        setTargetResults,
                                        setTargetSearching,
                                    );
                                }
                            }}
                        />
                        <button
                            type="button"
                            className="inline-flex h-9 items-center gap-1 rounded-md border px-2 text-xs disabled:opacity-50"
                            onClick={() =>
                                void searchClusters(
                                    targetQuery,
                                    setTargetResults,
                                    setTargetSearching,
                                )
                            }
                            disabled={
                                membershipDisabled || busy || targetSearching
                            }
                        >
                            <Search className="size-3.5" />
                            {targetSearching ? copy.searching : copy.search}
                        </button>
                    </div>
                    {targetResults.length > 0 && (
                        <div className="mt-2 max-h-36 overflow-auto rounded-md border p-1">
                            {targetResults.map((result) => (
                                <ResultButton
                                    key={result.id}
                                    label={result.name}
                                    meta={
                                        result.is_locked
                                            ? copy.locked
                                            : copy.targetCluster
                                    }
                                    onClick={() => setMoveTarget(result)}
                                    disabled={result.is_locked}
                                />
                            ))}
                        </div>
                    )}
                    {moveTarget && (
                        <div className="mt-2 rounded-md bg-muted/50 p-2 text-xs">
                            {copy.targetCluster}:{' '}
                            <strong>{moveTarget.name}</strong>
                        </div>
                    )}
                    <button
                        type="button"
                        className="mt-3 inline-flex h-9 items-center rounded-md border border-primary bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-50"
                        onClick={move}
                        disabled={
                            membershipDisabled ||
                            busy ||
                            !moveMember ||
                            !moveTarget ||
                            moveTarget.is_locked ||
                            (moveMember.is_primary && !moveReplacement)
                        }
                    >
                        {copy.moveMember}
                    </button>
                </section>

                <section className="rounded-lg border bg-background p-3">
                    <div className="flex items-center gap-2 text-sm font-medium">
                        <Scissors className="size-4" />
                        {copy.split}
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {copy.splitDescription}
                    </p>
                    <label className="mt-3 block text-xs font-medium">
                        {copy.splitName}
                        <input
                            className="mt-1 h-9 w-full rounded-md border bg-background px-3 text-xs"
                            value={splitName}
                            onChange={(event) =>
                                setSplitName(event.target.value)
                            }
                            placeholder={copy.splitNamePlaceholder}
                            disabled={membershipDisabled || busy}
                        />
                    </label>
                    <p className="mt-3 text-xs font-medium">
                        {copy.splitMembers}
                    </p>
                    <p className="mt-1 text-[11px] text-muted-foreground">
                        {copy.searchMembers}
                    </p>
                    {memberResults.length === 0 && (
                        <button
                            type="button"
                            className="mt-2 inline-flex h-9 items-center gap-1 rounded-md border px-2 text-xs disabled:opacity-50"
                            onClick={() => void searchMembers()}
                            disabled={
                                membershipDisabled || busy || memberSearching
                            }
                        >
                            <Search className="size-3.5" />
                            {memberSearching ? copy.searching : copy.search}
                        </button>
                    )}
                    {memberResults.length > 0 && (
                        <div className="mt-2 max-h-44 overflow-auto rounded-md border p-1">
                            {memberResults.map((member) => {
                                const selected = splitMembers.some(
                                    (item) =>
                                        item.keyword_id === member.keyword_id,
                                );
                                return (
                                    <button
                                        key={`split-${member.id}`}
                                        type="button"
                                        className="flex w-full items-center justify-between gap-2 rounded-md px-2 py-2 text-start text-xs hover:bg-muted"
                                        onClick={() =>
                                            toggleSplitMember(member)
                                        }
                                    >
                                        <span className="truncate">
                                            {member.display_text ??
                                                `#${member.keyword_id}`}
                                            {member.is_primary
                                                ? ` · ${copy.primary}`
                                                : ''}
                                        </span>
                                        <span className="shrink-0 text-primary">
                                            {selected
                                                ? copy.remove
                                                : copy.choose}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    {splitMembers.length > 0 && (
                        <div className="mt-3 space-y-2">
                            <label className="block text-xs font-medium">
                                {copy.splitPrimary}
                                <select
                                    className="mt-1 h-9 w-full rounded-md border bg-background px-2 text-xs"
                                    value={splitPrimaryId ?? ''}
                                    onChange={(event) =>
                                        setSplitPrimaryId(
                                            event.target.value === ''
                                                ? null
                                                : Number(event.target.value),
                                        )
                                    }
                                    disabled={membershipDisabled || busy}
                                >
                                    <option value="">—</option>
                                    {splitMembers.map((member) => (
                                        <option
                                            key={member.keyword_id}
                                            value={member.keyword_id}
                                        >
                                            {member.display_text ??
                                                `#${member.keyword_id}`}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <p className="text-[11px] text-muted-foreground">
                                {splitMembers.length} {copy.members}
                            </p>
                        </div>
                    )}
                    {splitContainsCurrentPrimary && (
                        <div className="mt-3 rounded-md border border-amber-500/30 p-2">
                            <p className="text-xs text-muted-foreground">
                                {copy.splitReplacementHint}
                            </p>
                            <div className="mt-2 max-h-32 overflow-auto">
                                {availableMemberResults.map((member) => (
                                    <ResultButton
                                        key={`split-replacement-${member.id}`}
                                        label={
                                            member.display_text ??
                                            `#${member.keyword_id}`
                                        }
                                        meta={copy.replacementPrimary}
                                        onClick={() =>
                                            setSplitReplacement(member)
                                        }
                                    />
                                ))}
                            </div>
                            {splitReplacement && (
                                <p className="mt-2 text-xs">
                                    {copy.selected}:{' '}
                                    <strong>
                                        {splitReplacement.display_text ??
                                            `#${splitReplacement.keyword_id}`}
                                    </strong>
                                </p>
                            )}
                        </div>
                    )}
                    <button
                        type="button"
                        className="mt-3 inline-flex h-9 items-center rounded-md border border-primary bg-primary px-3 text-xs font-medium text-primary-foreground disabled:opacity-50"
                        onClick={split}
                        disabled={
                            membershipDisabled ||
                            busy ||
                            splitName.trim() === '' ||
                            splitMembers.length === 0 ||
                            !splitPrimaryId ||
                            splitMembers.length >= cluster.member_count ||
                            (splitContainsCurrentPrimary && !splitReplacement)
                        }
                    >
                        {copy.createSplit}
                    </button>
                </section>
            </div>

            {error && <p className="mt-3 text-xs text-destructive">{error}</p>}
        </details>
    );
}
