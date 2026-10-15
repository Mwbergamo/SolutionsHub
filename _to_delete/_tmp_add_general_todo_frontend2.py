#!/usr/bin/env python3
import sys

path = "app.js"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()

orig = src

# ---------------------------------------------------------------------------
# 1. Rewrite activityPanelHtml() so the To-Do card renders independently of
#    ConnectWise ticket-summary availability (a mock/prospect customer with
#    no ConnectWise id still gets a working To-Do list), and add the
#    drilldown call.
# ---------------------------------------------------------------------------
old1 = """  function activityPanelHtml(detail) {
    var summary = state.activitySummary;
    if (state.activitySummaryLoading && !summary) {
      return '<div class="activity-panel"><div class="activity-card loading-card">Loading ticket & billing activity…</div></div>';
    }
    if (!summary) {
      return '';
    }
    if (summary.available === false) {
      if (summary.error) {
        return '<div class="activity-panel"><div class="activity-card error-card">' +
          'Couldn’t load ticket/billing activity from ConnectWise: ' + escapeHtml(summary.error) +
        '</div></div>';
      }
      return ''; // mock customer -- no ConnectWise id, nothing to show, not an error
    }

    var html = '<div class="activity-panel">';

    html += '<button class="activity-card" type="button" data-action="open-tickets">' +
      '<div class="activity-card-label">Service Tickets YTD</div>' +
      '<div class="activity-card-value">' + summary.ticket_count_ytd + '</div>' +
      '<div class="activity-card-sub">Professional Services board — click to view</div>' +
    '</button>';

    html += '</div>';
    html += activityDrilldownHtml(detail);
    return html;
  }"""
assert src.count(old1) == 1, "old1 count=%d" % src.count(old1)

new1 = """  // The To-Do count card (added 2026-10-15, see state.todoSummary above)
  // renders in the same .activity-panel grid as Service Tickets YTD --
  // "right next to" it, per Michael -- but independently of whether a
  // ConnectWise ticket summary is available, so a mock/prospect customer
  // with no ConnectWise id still gets a working To-Do list.
  function activityPanelHtml(detail) {
    var summary = state.activitySummary;
    var ticketsHtml = '';
    if (state.activitySummaryLoading && !summary) {
      ticketsHtml = '<div class="activity-card loading-card">Loading ticket & billing activity…</div>';
    } else if (summary) {
      if (summary.available === false) {
        if (summary.error) {
          ticketsHtml = '<div class="activity-card error-card">' +
            'Couldn’t load ticket/billing activity from ConnectWise: ' + escapeHtml(summary.error) +
          '</div>';
        }
        // else: mock customer -- no ConnectWise id, nothing to show, not an error
      } else {
        ticketsHtml = '<button class="activity-card" type="button" data-action="open-tickets">' +
          '<div class="activity-card-label">Service Tickets YTD</div>' +
          '<div class="activity-card-value">' + summary.ticket_count_ytd + '</div>' +
          '<div class="activity-card-sub">Professional Services board — click to view</div>' +
        '</button>';
      }
    }

    var todoHtml = todoCardHtml(detail);
    if (!ticketsHtml && !todoHtml) return '';

    var html = '<div class="activity-panel">' + ticketsHtml + todoHtml + '</div>';
    html += activityDrilldownHtml(detail);
    html += todoDrilldownHtml(detail);
    return html;
  }

  // The To-Do count card itself -- white (0 open to-dos), green (open
  // to-dos exist, none overdue -- an undated to-do counts as not-yet-due,
  // per Michael/AskUserQuestion 2026-10-15), or red (at least one open,
  // dated to-do whose due date has passed). Counts and color inputs come
  // straight from todo_summary (api/meetings.php) so this file doesn't
  // duplicate that logic.
  function todoCardHtml(detail) {
    var summary = state.todoSummary;
    if (state.todoSummaryLoading && !summary) {
      return '<div class="activity-card loading-card">Loading to-dos…</div>';
    }
    if (!summary) return '';
    if (summary.error) {
      return '<div class="activity-card error-card">Couldn’t load to-dos: ' + escapeHtml(summary.error) + '</div>';
    }
    var colorClass = 'todo-white';
    if (summary.overdue_count > 0) colorClass = 'todo-red';
    else if (summary.open_count > 0) colorClass = 'todo-green';
    return '<button class="activity-card todo-card ' + colorClass + '" type="button" data-action="open-todos">' +
      '<div class="activity-card-label">To-Do List</div>' +
      '<div class="activity-card-value">' + summary.open_count + '</div>' +
      '<div class="activity-card-sub">' + (summary.overdue_count > 0
        ? summary.overdue_count + ' overdue — click to view'
        : 'Click to view') + '</div>' +
    '</button>';
  }

  // The To-Do list drill-down -- merged general_todos + meeting_tasks for
  // this customer (api/meetings.php's todo_list), each row tagged by
  // source. Same drilldown-header/back-button pattern as
  // activityDrilldownHtml() above.
  function todoDrilldownHtml(detail) {
    if (state.todoView !== 'list') return '';
    var html = '<div class="activity-drilldown">';
    html += '<div class="drilldown-header">' + activityDrilldownBackBtn('todos-close', 'Close') +
      '<div class="drilldown-title">To-Do List — ' + escapeHtml(detail.customer.name) + '</div>' +
    '</div>';

    if (state.todoListLoading && !state.todoList) {
      html += '<div class="loading">Loading to-dos…</div>';
    } else {
      if (state.todoListError) {
        html += '<div class="activity-error">' + escapeHtml(state.todoListError) + '</div>';
      }
      var list = state.todoList || { open_todos: [], recent_completed_todos: [], roster: [] };

      if (state.todoAddOpen) {
        html += todoAddFormHtml(detail, list.roster || []);
      } else {
        html += '<button type="button" class="meetings-add-btn small" data-action="todo-add-open">+ Add To-Do</button>';
      }

      if ((list.open_todos || []).length === 0) {
        html += '<div class="empty-state">No open to-dos.</div>';
      } else {
        html += '<div class="checklist-steps">';
        list.open_todos.forEach(function (t) { html += generalTodoItemHtml(t, detail.customer.id); });
        html += '</div>';
      }

      if ((list.recent_completed_todos || []).length > 0) {
        html += '<div class="roster-title">Recently completed</div><div class="checklist-steps">';
        list.recent_completed_todos.forEach(function (t) { html += generalTodoItemHtml(t, detail.customer.id); });
        html += '</div>';
      }
    }

    html += '</div>';
    return html;
  }

  // Same add-form markup/pattern as the meeting Add Task form
  // (meetingsPanelHtml's taskAssigneeSelect/taskDueDateInput) -- blank-
  // first assignee dropdown, per Michael's "a rep has to choose" rule.
  function todoAddFormHtml(detail, roster) {
    return '<div class="task-add-form">' +
      '<input type="text" id="todoDescriptionInput" class="meeting-form-input" placeholder="To-do description" value="' + escapeHtml(state.todoDraftDescription) + '" maxlength="500">' +
      '<select id="todoAssigneeSelect" class="meeting-form-select">' +
        '<option value=""' + (state.todoDraftAssignee === '' ? ' selected' : '') + '>Select a rep…</option>' +
        roster.map(function (name) {
          return '<option value="' + escapeHtml(name) + '"' + (state.todoDraftAssignee === name ? ' selected' : '') + '>' + escapeHtml(name) + '</option>';
        }).join('') +
      '</select>' +
      '<label class="task-due-date-label">Due date (optional)' +
        '<input type="date" id="todoDueDateInput" class="meeting-form-input" value="' + escapeHtml(state.todoDraftDueDate) + '">' +
      '</label>' +
      '<div class="meeting-form-actions">' +
        '<button type="button" class="vendor-field-btn primary" data-action="todo-save" data-customer="' + detail.customer.id + '" ' + (state.todoSaving ? 'disabled' : '') + '>' + (state.todoSaving ? 'Saving…' : 'Add To-Do') + '</button>' +
        '<button type="button" class="vendor-field-btn secondary" data-action="todo-add-cancel" ' + (state.todoSaving ? 'disabled' : '') + '>Cancel</button>' +
      '</div>' +
    '</div>';
  }

  // One merged to-do row -- same visual pattern as meetingTaskItemHtml()
  // below (the "same formatting as the Check-list items" rule), plus a
  // "From meeting" tag for meeting-sourced rows and reassign/remove
  // controls for general-sourced rows only (meeting to-dos were never
  // reassignable/removable here either -- see meetings.php). Checking the
  // box dispatches to the 'general' or 'meeting' toggle action depending
  // on t.source -- see the dispatcher below.
  function generalTodoItemHtml(t, customerId) {
    var isDone = !!t.completed_at;
    var isGeneral = t.source === 'general';
    var toggling = isGeneral ? (state.todoTogglingId === t.id) : (state.taskTogglingId === t.id);
    var removing = state.todoRemovingId === t.id;
    var reassigning = state.todoReassigningId === t.id;
    var metaLine = isDone
      ? '✓ ' + escapeHtml(t.completed_by_name) + ' — ' + escapeHtml(fmtTimestamp(t.completed_at))
      : 'Assigned to ' + escapeHtml(t.assigned_to_name) + (t.due_date ? ' · Due ' + escapeHtml(fmtOutgrowDate(t.due_date)) : '');
    var cwWarn = t.cw_push && t.cw_push.status === 'error'
      ? ' <span class="meeting-task-cw-warn" title="' + escapeHtml(t.cw_push.error || '') + '">⚠</span>'
      : '';
    var cwCloseWarn = t.cw_close && t.cw_close.status === 'error'
      ? ' <span class="meeting-task-cw-warn" title="' + escapeHtml('Didn’t close in ConnectWise: ' + (t.cw_close.error || '')) + '">⚠</span>'
      : '';
    var meetingTag = t.source === 'meeting'
      ? ' <span class="todo-meeting-tag" title="Logged from a customer meeting">From meeting: ' + escapeHtml(t.meeting_subject || '') + '</span>'
      : '';

    var html = '<label class="checklist-step ' + (isDone ? 'done' : '') + '" data-task-row="' + t.id + '">' +
      '<input type="checkbox" ' + (isDone ? 'checked' : '') + (toggling ? ' disabled' : '') +
        ' data-action="' + (isGeneral ? 'todo-toggle-done' : 'task-toggle-done') + '" data-task="' + t.id + '" data-customer="' + customerId + '" data-completed="' + (isDone ? '1' : '0') + '">' +
      '<div class="checklist-step-text">' +
        '<div class="checklist-step-label">' + escapeHtml(t.description) + meetingTag + cwWarn + cwCloseWarn + '</div>' +
        '<div class="checklist-step-meta">' + metaLine + '</div>' +
      '</div>' +
    '</label>';

    if (isGeneral && !isDone) {
      html += '<div class="todo-row-actions">' +
        '<select class="meeting-form-select small" data-todo-reassign-select="' + t.id + '" ' + (reassigning ? 'disabled' : '') + '>' +
          '<option value="">Reassign to…</option>' +
          ((state.todoList && state.todoList.roster) || []).map(function (name) {
            return '<option value="' + escapeHtml(name) + '"' + (state.todoReassignDraft[t.id] === name ? ' selected' : '') + '>' + escapeHtml(name) + '</option>';
          }).join('') +
        '</select>' +
        '<button type="button" class="vendor-field-btn secondary small" data-action="todo-reassign" data-todo="' + t.id + '" data-customer="' + customerId + '" ' + (reassigning ? 'disabled' : '') + '>' + (reassigning ? '…' : 'Reassign') + '</button>' +
        '<button type="button" class="vendor-field-btn secondary small" data-action="todo-remove" data-todo="' + t.id + '" data-customer="' + customerId + '" ' + (removing ? 'disabled' : '') + '>' + (removing ? '…' : 'Remove') + '</button>' +
      '</div>';
    }

    return html;
  }"""
src = src.replace(old1, new1, 1)
assert src != orig, "activityPanelHtml rewrite did not apply"

with open(path, "w", encoding="utf-8") as f:
    f.write(src)

print("OK -- part 2a (html-building functions) applied")
