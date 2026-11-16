// W-34: default feed handlers (no posts in the mock db). Feed tests override them with
// `server.use(...)` and their own fixtures.
import { http } from 'msw';
import type { PostPage } from '@/api/types';
import { json, noContent, problem, requireUser } from './handlerUtils';

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
  // W-81 (ADR 015): no posts in the mock db, so nothing was reposted and no post can be.
  http.get(`${API}/users/:username/reposts`, ({ request }) => {
    requireUser(request);
    return json(empty);
  }),
  http.put(`${API}/posts/:postId/repost`, ({ request }) => {
    requireUser(request);
    return problem(404, 'not_found');
  }),
  http.delete(`${API}/posts/:postId/repost`, ({ request }) => {
    requireUser(request);
    return problem(404, 'not_found');
  }),
  http.post(`${API}/post-views`, ({ request }) => {
    requireUser(request);
    return noContent();
  }),
];
