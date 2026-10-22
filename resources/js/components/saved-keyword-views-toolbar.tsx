import { useEffect, useRef } from 'react';
import {
    type SavedKeywordView,
    type SavedKeywordViewState,
    SavedKeywordViews,
} from '@/components/saved-keyword-views';

export type { SavedKeywordView, SavedKeywordViewState };

type Props = {
    path: string;
    savedViews: SavedKeywordView[];
    activeView: SavedKeywordView | null;
    currentState: SavedKeywordViewState;
    disabled?: boolean;
    onApplyColumns: (columns: string[]) => void;
};

export function SavedKeywordViewsToolbar({
    path,
    savedViews,
    activeView,
    currentState,
    disabled = false,
    onApplyColumns,
}: Props) {
    const applyColumnsRef = useRef(onApplyColumns);
    applyColumnsRef.current = onApplyColumns;

    useEffect(() => {
        if (activeView !== null) {
            applyColumnsRef.current(activeView.state.columns);
        }
    }, [activeView]);

    return (
        <SavedKeywordViews
            path={path}
            savedViews={savedViews}
            activeView={activeView}
            currentState={currentState}
            disabled={disabled}
        />
    );
}
