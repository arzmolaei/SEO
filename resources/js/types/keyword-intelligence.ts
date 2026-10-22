export type IntelligenceMetricEvidence = {
    metric_id: number;
    source_id: number;
    source: string | null;
    observed_at: string;
};

export type IntelligencePriorityComponent = {
    value: number | boolean | null;
    points: number;
    weight: number;
    available: boolean;
    evidence?: IntelligenceMetricEvidence | null;
};

export type IntelligencePriority = {
    score: number;
    data_completeness: number;
    components: Record<string, IntelligencePriorityComponent>;
};

export type IntelligenceTargetPage = {
    id: number;
    title: string | null;
    kind: 'planned' | 'existing';
    page_type: string | null;
    url: string | null;
};

export type IntelligenceCluster = {
    id: number;
    name: string;
    intent: string;
    confidence: string;
    review_state: 'pending' | 'reviewed';
    business_relevance: number | null;
    lifecycle_state: 'planning' | 'ready';
    is_locked: boolean;
    revision: number;
    member_count: number;
    primary_keyword: {
        id: number;
        display_text: string;
    };
    target: null | {
        id: number;
        type: string;
        revision: number;
        page: IntelligenceTargetPage | null;
    };
    priority: IntelligencePriority;
};

export type IntelligencePaginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type KeywordIntelligenceWorkspace = {
    project_id: number;
    can_manage: boolean;
    summary: {
        active_clusters: number;
        ready_clusters: number;
        unmapped_clusters: number;
        pending_reviews: number;
        mapping_conflicts: number;
    };
    clusters: IntelligencePaginator<IntelligenceCluster>;
};
