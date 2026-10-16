#!/usr/bin/env python3
path = "relationships/app.js"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()
orig = src

# 1) New state fields -- the front-page To-Do gauge's own drill-down list,
#    right next to overviewListMode (same front-page-list family).
old1 = """    outgrowSortDir: 'asc',
    outgrowBackfillStarted: false,
"""
assert src.count(old1) == 1, "state1 count=%d" % src.count(old1)
new1 = old1 + """
    // Front-page "To-Do List" gauge (added 2026-10-16, per Michael) -- a
    // separate drill-down from overviewListMode above (its own bucket, not
    // a customer group): null (hidden) | 'overdue' | 'due_today' |
    // 'future'. Clicking one of the gauge's three numbers
    // (toggle-todo-overview) sets this and lazy-loads the merged list;
    // clicking the same one again hides it. See gaugesHtml()/
    // todoOverviewPanelHtml().
    todoOverviewBucket: null,
    todoOverviewLoading: false,
    todoOverviewData: null, // { bucket, items } | null
    todoOverviewError: null,
"""
assert src.count(old1) == 1
src = src.replace(old1, new1, 1)

# 2) Deep-link flag for a general-todo row in the cross-customer list --
#    same pattern as pendingTaskFocus/pendingRiskScanFocus just above it,
#    but there's no meeting/task id to carry (a general to-do isn't tied to
#    a meeting) -- selectCustomer() just needs to know to open that
#    customer's merged To-Do drilldown once loaded.
old2 = "    pendingTaskFocus: null, // { meetingId, taskId } | null"
assert src.count(old2) == 1, "state2 count=%d" % src.count(old2)
new2 = old2 + """
    // Global To-Do gauge (front page) -> a specific customer's general
    // to-do -- same deep-link idea as pendingTaskFocus above, but a
    // general to-do has no meeting to open, so this just tells
    // selectCustomer() to open that customer's merged To-Do drilldown
    // (loadTodoList()) once their detail has loaded. Consumed once, same
    // "set then clear" pattern as every other pending*Focus flag here.
    pendingTodoListFocus: false,"""
assert src.count(old2) == 1
src = src.replace(old2, new2, 1)

# 3) selectCustomer() -- open the merged To-Do drilldown automatically when
#    deep-linked from the front-page gauge's general-todo rows.
old3 = """        resetTodoState();
        loadTodoSummary(id);
        resetRiskScansState();"""
assert src.count(old3) == 1, "selectCustomer anchor count=%d" % src.count(old3)
new3 = """        resetTodoState();
        loadTodoSummary(id);
        if (state.pendingTodoListFocus) {
          state.pendingTodoListFocus = false;
          loadTodoList(id);
        }
        resetRiskScansState();"""
assert src.count(old3) == 1
src = src.replace(old3, new3, 1)

# 4) Deep-link helper, right after openCustomerAtTask() (same family).
old4 = """  function openCustomerAtTask(customerId, meetingId, taskId) {
    state.view = 'dashboard';
    state.pendingTaskFocus = { meetingId: meetingId, taskId: taskId };
    selectCustomer(customerId);
  }"""
assert src.count(old4) == 1, "openCustomerAtTask count=%d" % src.count(old4)
new4 = old4 + """

  // Global To-Do gauge -> a specific customer's general to-do, same
  // deep-link pattern as openCustomerAtTask() above -- the merged
  // drilldown (loadTodoList(), consumed via pendingTodoListFocus inside
  // selectCustomer()) shows both to-do sources already, so there's
  // nothing further to focus once it's open.
  function openCustomerAtGeneralTodo(customerId) {
    state.view = 'dashboard';
    state.pendingTodoListFocus = true;
    selectCustomer(customerId);
  }

  // ---- Front-page "To-Do List" gauge drilldown (api/meetings.php's
  // todo_overview) -- added 2026-10-16, per Michael's three-number global
  // to-do card (Overdue / Due Today / Future). Same lazy-load-on-click,
  // click-again-to-close idiom as toggle-overview-list.
  function toggleTodoOverview(bucket) {
    if (state.todoOverviewBucket === bucket) {
      state.todoOverviewBucket = null;
      render();
      return;
    }
    state.todoOverviewBucket = bucket;
    state.todoOverviewData = null;
    state.todoOverviewError = null;
    render();
    loadTodoOverview(bucket);
  }

  function loadTodoOverview(bucket) {
    state.todoOverviewLoading = true;
    state.todoOverviewError = null;
    render();
    apiGet('api/meetings.php?action=todo_overview&bucket=' + encodeURIComponent(bucket)).then(function (r) {
      if (state.todoOverviewBucket !== bucket) return; // closed/switched while in flight
      state.todoOverviewLoading = false;
      if (r.data && r.data.ok) {
        state.todoOverviewData = r.data;
      } else {
        state.todoOverviewError = (r.data && r.data.error) || 'Could not load that to-do list.';
      }
      render();
    }).catch(function () {
      if (state.todoOverviewBucket !== bucket) return;
      state.todoOverviewLoading = false;
      state.todoOverviewError = 'Could not load that to-do list \\u2014 check your connection.';
      render();
    });
  }

  var TODO_OVERVIEW_BUCKET_LABEL = { overdue: 'Overdue', due_today: 'Due Today', future: 'Future' };

  // Rendered inside overviewBodyHtml(), below the gauges -- same slot
  // outgrowStaleListHtml()/customerOverviewListHtml() use for
  // overviewListMode, but this is its own independent toggle
  // (todoOverviewBucket) so opening a to-do bucket doesn't fight with an
  // open customer-group list, or vice versa.
  function todoOverviewPanelHtml() {
    var bucket = state.todoOverviewBucket;
    if (!bucket) return '';
    var label = TODO_OVERVIEW_BUCKET_LABEL[bucket] || bucket;
    var html = '<div class="todo-overview-panel">';
    html += '<div class="todo-overview-panel-title">' + escapeHtml(label) + ' To-Dos</div>';
    if (state.todoOverviewError) {
      html += '<div class="error-banner">' + escapeHtml(state.todoOverviewError) + '</div>';
    }
    if (state.todoOverviewLoading && !state.todoOverviewData) {
      return html + '<div class="loading">Loading\\u2026</div></div>';
    }
    if (!state.todoOverviewData) return html + '</div>';
    var items = state.todoOverviewData.items || [];
    if (items.length === 0) {
      html += '<div class="roster-empty">Nothing here.</div></div>';
      return html;
    }
    html += '<div class="global-todo-list">';
    items.forEach(function (t) {
      var dueText = t.due_date ? fmtDate(t.due_date) : 'No due date';
      var metaBits = [escapeHtml(t.customer_name)];
      if (t.meeting_subject) metaBits.push('\\u201c' + escapeHtml(t.meeting_subject) + '\\u201d');
      metaBits.push(escapeHtml(t.assigned_to_name));
      metaBits.push(escapeHtml(dueText));
      var action = t.source === 'meeting' ? 'open-customer-task' : 'open-customer-todo';
      var attrs = 'data-action="' + action + '" data-customer="' + t.customer_id + '"';
      if (t.source === 'meeting') attrs += ' data-meeting="' + t.meeting_id + '" data-task="' + t.id + '"';
      html += '<div class="global-todo-item" ' + attrs + '>' +
        '<div class="global-todo-item-main">' +
          '<div class="global-todo-item-desc">' + escapeHtml(t.description) + '</div>' +
          '<div class="global-todo-item-meta">' + metaBits.join(' \\u00b7 ') + '</div>' +
        '</div>' +
        '<div class="global-todo-item-go">Open \\u2192</div>' +
      '</div>';
    });
    html += '</div></div>';
    return html;
  }"""
assert src.count(old4) == 1
src = src.replace(old4, new4, 1)

# 5) overviewBodyHtml() -- render the gauge's own drilldown below the
#    gauges, alongside (not instead of) the existing customer-group list.
old5 = """    if (state.overview) {
      return gaugesHtml(state.overview.gauges || []) + leaderboardsHtml(state.overview.leaderboards || []) +
        (state.overviewListMode === 'outgrow'
          ? outgrowStaleListHtml(state.overview.customers || [])
          : (state.overviewListMode ? customerOverviewListHtml(state.overview.customers || []) : ''));
    }"""
assert src.count(old5) == 1, "overviewBodyHtml anchor count=%d" % src.count(old5)
new5 = """    if (state.overview) {
      return gaugesHtml(state.overview.gauges || []) + leaderboardsHtml(state.overview.leaderboards || []) +
        todoOverviewPanelHtml() +
        (state.overviewListMode === 'outgrow'
          ? outgrowStaleListHtml(state.overview.customers || [])
          : (state.overviewListMode ? customerOverviewListHtml(state.overview.customers || []) : ''));
    }"""
assert src.count(old5) == 1
src = src.replace(old5, new5, 1)

# 6) gaugesHtml() -- new 'todo3' tile format: three independently clickable
#    numbers in one card (red/yellow/green), instead of the single
#    value+hint the other clickable tiles use.
old6 = """      } else if (g.key === 'total_customers' || g.key === 'total_prospects' || g.key === 'total_residential' || g.key === 'outgrow_stale') {"""
assert src.count(old6) == 1, "gaugesHtml anchor count=%d" % src.count(old6)
new6 = """      } else if (g.format === 'todo3') {
        html += '<div class="gauge-tile gauge-tile-todo3">' +
          '<div class="gauge-label">' + escapeHtml(g.label) + '</div>' +
          '<div class="todo3-row">' +
            todo3StatHtml('overdue', 'Overdue', g.overdue) +
            todo3StatHtml('due_today', 'Due Today', g.due_today) +
            todo3StatHtml('future', 'Future', g.future) +
          '</div>' +
        '</div>';
      } else if (g.key === 'total_customers' || g.key === 'total_prospects' || g.key === 'total_residential' || g.key === 'outgrow_stale') {"""
assert src.count(old6) == 1
src = src.replace(old6, new6, 1)

# 7) Small helper for each of the three todo3 buttons -- right before
#    gaugesHtml() so it reads top-down (helper, then the function that
#    uses it), matching this file's usual ordering for small shared bits.
old7 = "  function gaugesHtml(gauges) {"
assert src.count(old7) == 1, "gaugesHtml def count=%d" % src.count(old7)
new7 = """  // One number in the To-Do gauge's three-way split (gaugesHtml() below).
  // bucket matches a meetings.php todo_overview bucket name 1:1 -- kept as
  // the data-bucket attribute so toggleTodoOverview() needs no translation.
  function todo3StatHtml(bucket, label, value) {
    var active = state.todoOverviewBucket === bucket;
    return '<button type="button" class="todo3-stat todo3-stat-' + bucket.replace('_', '-') + (active ? ' active' : '') + '" ' +
      'data-action="toggle-todo-overview" data-bucket="' + bucket + '" title="' + (active ? 'Hide the list' : 'Show the list') + '">' +
      '<span class="todo3-stat-value">' + (value == null ? '\\u2014' : value) + '</span>' +
      '<span class="todo3-stat-label">' + escapeHtml(label) + '</span>' +
    '</button>';
  }

  function gaugesHtml(gauges) {"""
assert src.count(old7) == 1
src = src.replace(old7, new7, 1)

# 8) Dispatcher wiring -- toggle-todo-overview and open-customer-todo,
#    right alongside the existing open-customer-task/open-customer-riskscan
#    cases.
old8 = """    } else if (action === 'open-customer-riskscan') {
      openCustomerAtRiskScan(
        parseInt(el.getAttribute('data-customer'), 10),
        parseInt(el.getAttribute('data-scan'), 10)
      );"""
assert src.count(old8) == 1, "dispatcher anchor count=%d" % src.count(old8)
new8 = old8 + """
    } else if (action === 'open-customer-todo') {
      openCustomerAtGeneralTodo(parseInt(el.getAttribute('data-customer'), 10));
    } else if (action === 'toggle-todo-overview') {
      toggleTodoOverview(el.getAttribute('data-bucket'));"""
assert src.count(old8) == 1
src = src.replace(old8, new8, 1)

assert src != orig
with open(path, "w", encoding="utf-8") as f:
    f.write(src)
print("app.js updated: To-Do List gauge (todo3) + drilldown wired up")
