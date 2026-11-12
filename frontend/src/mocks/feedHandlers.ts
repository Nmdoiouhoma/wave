// W-34: default feed handlers (no posts in the mock db). Feed tests override them with
// `server.use(...)` and their own fixtures.
import { http } from 'msw';
import type { PostPage } from '@/api/types';
import { json, noContent, requireUser } from './handlerUtils';

const API = '*/api/v1';
const empty: PostPage = { items: [], nextCursor: null };

export const feedHandlers = [
  http.get(`${API}/feed/for-you`, ({ request }) => {
    requireUser(request);
    return json(empty);
  }),
  http.get(`${API}/feed/following`, ({ request }) => {
    requireUser(request);
    return json(empty);
  }),
  http.post(`${API}/post-views`, ({ request }) => {
    requireUser(request);
    return noContent();
  }),
];
