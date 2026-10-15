#!/usr/bin/env python3
import re, sys

path = "app.js"
with open(path, "r", encoding="utf-8") as f:
    src = f.read()

orig = src

# ---------------------------------------------------------------------------
# 1. New state fields, right after pendingTaskFocus.
# ---------------------------------------------------------------------------
anchor1 = "    pendingTaskFocus: null, // { meetingId, taskId } | null\n"
assert src.count(anchor1) == 1, "anchor1 count=%d" % src.count(anchor1)
block1 = anchor1 + """
    // General (customer-level) To-Do list -- added 2026-10-15 per Michael's
    // To-Do count card on the customer dashboard (api/meetings.php:
    // todo_summary/todo_list/add_general_todo/reassign_general_todo/
    // remove_general_todo/set_general_todo_done). Merged with meeting_tasks
    // for DISPLAY only -- see todoDrilldownHtml()/generalTodoItemHtml()
    // below -- each keeps its own lifecycle server-side.
    todoSummary: null, // { open_count, overdue_count } | { ..., error } | null while loading/not yet loaded
    todoSummaryLoading: false,
    todoView: null, // null | 'list' -- whether the drill-down is open
    todoList: null, // { open_todos, recent_completed_todos, roster } | null
    todoListLoading: false,
    todoListError: null,
    todoAddOpen: false,
    todoDraftDescription: '',
    todoDraftAssignee: '',
    todoDraftDueDate: '', // "YYYY-MM-DD" | ''
    todoSaving: false,
    todoTogglingId: null, // general_todos id currently mid-toggle
    todoReassigningId: null, // general_todos id currently mid-reassign request
    todoRemovingId: null, // general_todos id currently mid-remove
    todoReassignDraft: {}, // general_todos id (number) -> roster name picked, not yet submitted -- same pattern as riskScanAssignDraft above
"""
src = src.replace(anchor1, block1, 1)

# ---------------------------------------------------------------------------
# 2. resetTodoState(), right after resetMeetingsState() closes, before loadMeetings().
# ---------------------------------------------------------------------------
anchor2 = """    // target before loadMeetings() ever gets to consume it.
  }

  function loadMeetings(customerId) {"""
assert src.count(anchor2) == 1, "anchor2 count=%d" % src.count(anchor2)
block2 = """    // target before loadMeetings() ever gets to consume it.
  }

  function resetTodoState() {
    state.todoSummary = null;
    state.todoSummaryLoading = false;
    state.todoView = null;
    state.todoList = null;
    state.todoListLoading = false;
    state.todoListError = null;
    state.todoAddOpen = false;
    state.todoDraftDescription = '';
    state.todoDraftAssignee = '';
    state.todoDraftDueDate = '';
    state.todoSaving = false;
    state.todoTogglingId = null;
    state.todoReassigningId = null;
    state.todoRemovingId = null;
    state.todoReassignDraft = {};
  }

  function loadMeetings(customerId) {"""
src = src.replace(anchor2, block2, 1)

# ---------------------------------------------------------------------------
# 3. Call resetTodoState()/loadTodoSummary(id) from selectCustomer()'s success handler.
# ---------------------------------------------------------------------------
anchor3 = """        resetMeetingsState();
        loadMeetings(id);
        resetRiskScansState();"""
assert src.count(anchor3) == 1, "anchor3 count=%d" % src.count(anchor3)
block3 = """        resetMeetingsState();
        loadMeetings(id);
        resetTodoState();
        loadTodoSummary(id);
        resetRiskScansState();"""
src = src.replace(anchor3, block3, 1)

# ---------------------------------------------------------------------------
# 4. load/save/toggle/reassign/remove functions for general to-dos, right
#    before the "Global master to-do dashboard" comment (after toggleTaskDone()).
# ---------------------------------------------------------------------------
anchor4 = """      if (formstackTab) formstackTab.close();
      render();
    });
  }

  // ---- Global master to-do dashboard (Relationships front page) ---------"""
assert src.count(anchor4) == 1, "anchor4 count=%d" % src.count(anchor4)
block4 = """      if (formstackTab) formstackTab.close();
      render();
    });
  }

  // ---- General (customer-level) To-Do list -- api/meetings.php's
  // todo_summary/todo_list/add_general_todo/reassign_general_todo/
  // remove_general_todo/set_general_todo_done. Same stale-response guard
  // (Number(customerId) !== requestFor) as loadActivitySummary/loadMeetings
  // above. toggleGeneralTodoDone() below only ever targets a
  // 'general'-source row -- a 'meeting'-source row in the merged drilldown
  // dispatches to the EXISTING task-toggle-done/toggleTaskDone() instead
  // (see generalTodoItemHtml()), which is exactly why checking it off here
  // also closes it in the meeting menu: there's only one meeting_tasks row,
  // read and written by both views.

  function loadTodoSummary(customerId) {
    state.todoSummaryLoading = true;
    var requestFor = Number(customerId);
    apiGet('api/meetings.php?action=todo_summary&customer_id=' + encodeURIComponent(customerId)).then(function (r) {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      state.todoSummaryLoading = false;
      if (r.data && r.data.ok) {
        state.todoSummary = { open_count: r.data.open_count, overdue_count: r.data.overdue_count };
      } else {
        state.todoSummary = { open_count: 0, overdue_count: 0, error: (r.data && r.data.error) || 'Could not load to-dos.' };
      }
      render();
    }).catch(function () {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      state.todoSummaryLoading = false;
      state.todoSummary = { open_count: 0, overdue_count: 0, error: 'Could not load to-dos — check your connection.' };
      render();
    });
  }

  function loadTodoList(customerId) {
    state.todoView = 'list';
    state.todoListLoading = true;
    state.todoListError = null;
    var requestFor = Number(customerId);
    render();
    apiGet('api/meetings.php?action=todo_list&customer_id=' + encodeURIComponent(customerId)).then(function (r) {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      state.todoListLoading = false;
      if (r.data && r.data.ok) {
        state.todoList = r.data;
      } else {
        state.todoListError = (r.data && r.data.error) || 'Could not load the to-do list.';
      }
      render();
    }).catch(function () {
      if (!state.selectedCustomer || Number(state.selectedCustomer.customer.id) !== requestFor) return;
      state.todoListLoading = false;
      state.todoListError = 'Could not load the to-do list — check your connection and try again.';
      render();
    });
  }

  // After any mutation, the badge (todoSummary) and the open drill-down
  // list (todoList) need to agree again -- reload both together rather
  // than hand-patch counts client-side, same "reload after mutate" idiom
  // toggleTaskDone() already uses for state.repTodosData.
  function refreshTodoViews(customerId) {
    loadTodoSummary(customerId);
    loadTodoList(customerId);
  }

  function saveGeneralTodo(customerId) {
    var description = (state.todoDraftDescription || '').trim();
    var assignee = state.todoDraftAssignee;
    if (!description || !assignee) {
      state.todoListError = 'Enter a to-do description and pick who it’s assigned to.';
      render();
      return;
    }
    state.todoSaving = true;
    state.todoListError = null;
    render();
    apiPost('api/meetings.php?action=add_general_todo', {
      customer_id: customerId, description: description, assigned_to_name: assignee,
      due_date: state.todoDraftDueDate || null
    }).then(function (r) {
      state.todoSaving = false;
      if (r.data && r.data.ok) {
        state.todoAddOpen = false;
        state.todoDraftDescription = '';
        state.todoDraftAssignee = '';
        state.todoDraftDueDate = '';
        if (r.data.todo.cw_push && r.data.todo.cw_push.status === 'error') {
          state.todoListError = 'To-do saved here, but didn’t reach ConnectWise: ' + r.data.todo.cw_push.error;
        }
        refreshTodoViews(customerId);
      } else {
        state.todoListError = (r.data && r.data.error) || 'Could not save the to-do.';
        render();
      }
    }).catch(function () {
      state.todoSaving = false;
      state.todoListError = 'Could not save the to-do — check your connection and try again.';
      render();
    });
  }

  function toggleGeneralTodoDone(todoId, completed, customerId) {
    state.todoTogglingId = todoId;
    render();
    apiPost('api/meetings.php?action=set_general_todo_done', { todo_id: todoId, completed: completed }).then(function (r) {
      state.todoTogglingId = null;
      if (!r.data || !r.data.ok) {
        state.todoListError = (r.data && r.data.error) || 'Could not update the to-do.';
      }
      refreshTodoViews(customerId);
    }).catch(function () {
      state.todoTogglingId = null;
      state.todoListError = 'Could not update the to-do — check your connection and try again.';
      refreshTodoViews(customerId);
    });
  }

  function reassignGeneralTodo(todoId, assignedToName, customerId) {
    state.todoReassigningId = todoId;
    render();
    apiPost('api/meetings.php?action=reassign_general_todo', { todo_id: todoId, assigned_to_name: assignedToName }).then(function (r) {
      state.todoReassigningId = null;
      if (r.data && r.data.ok) {
        delete state.todoReassignDraft[todoId];
      } else {
        state.todoListError = (r.data && r.data.error) || 'Could not reassign the to-do.';
      }
      refreshTodoViews(customerId);
    }).catch(function () {
      state.todoReassigningId = null;
      state.todoListError = 'Could not reassign the to-do — check your connection and try again.';
      refreshTodoViews(customerId);
    });
  }

  function removeGeneralTodo(todoId, customerId) {
    var all = ((state.todoList && state.todoList.open_todos) || []).concat((state.todoList && state.todoList.recent_completed_todos) || []);
    var t = all.filter(function (x) { return x.id === todoId; })[0];
    if (!window.confirm('Remove the to-do “' + (t ? t.description : 'this to-do') + '”? This can’t be undone.')) return;
    state.todoRemovingId = todoId;
    render();
    apiPost('api/meetings.php?action=remove_general_todo', { todo_id: todoId }).then(function (r) {
      state.todoRemovingId = null;
      if (!r.data || !r.data.ok) {
        state.todoListError = (r.data && r.data.error) || 'Could not remove the to-do.';
      }
      refreshTodoViews(customerId);
    }).catch(function () {
      state.todoRemovingId = null;
      state.todoListError = 'Could not remove the to-do — check your connection and try again.';
      refreshTodoViews(customerId);
    });
  }

  // Delegated 'change' handler for the per-row reassign dropdowns in
  // generalTodoItemHtml() below -- several open general to-dos can be on
  // screen at once, so this is bound once on root (see the 'Bound once'
  // block near the end of this file), same pattern as
  // onRiskScanAssignSelectChange() above. Only tracks the pick so it
  // survives a re-render -- doesn't submit anything itself (see the
  // 'todo-reassign' dispatcher action, which reads this draft).
  function onTodoReassignSelectChange(ev) {
    var el = ev.target;
    var todoId = el.getAttribute && el.getAttribute('data-todo-reassign-select');
    if (!todoId) return;
    state.todoReassignDraft[todoId] = el.value;
  }

  // ---- Global master to-do dashboard (Relationships front page) ---------"""
src = src.replace(anchor4, block4, 1)

# ---------------------------------------------------------------------------
# 5. Refresh the merged To-Do drilldown after a MEETING-sourced toggle too,
#    so checking a meeting to-do off from the merged list keeps that list's
#    own badge/counts in sync (the meeting menu itself is already kept in
#    sync by the existing state.meetings patch just above this).
# ---------------------------------------------------------------------------
anchor5 = """        if (state.view === 'rep-todos' && state.repTodosName) {
          loadRepTodos(state.repTodosName);
        }
      } else if (formstackTab) {
        formstackTab.close();
      }
      render();
    }).catch(function () {
      state.taskTogglingId = null;"""
assert src.count(anchor5) == 1, "anchor5 count=%d" % src.count(anchor5)
block5 = """        if (state.view === 'rep-todos' && state.repTodosName) {
          loadRepTodos(state.repTodosName);
        }
        // Also refresh the merged To-Do drilldown if that's open (added
        // 2026-10-15) -- it shows meeting-sourced to-dos too, so a toggle
        // from there needs its own open_todos/recent_completed_todos
        // arrays (and the card's badge) back in sync, same "reload after
        // mutate" idiom as the rep-todos refresh just above.
        if (state.todoView === 'list' && state.selectedCustomer) {
          loadTodoSummary(state.selectedCustomer.customer.id);
          loadTodoList(state.selectedCustomer.customer.id);
        }
      } else if (formstackTab) {
        formstackTab.close();
      }
      render();
    }).catch(function () {
      state.taskTogglingId = null;"""
src = src.replace(anchor5, block5, 1)

assert src != orig, "no changes made"
with open(path, "w", encoding="utf-8") as f:
    f.write(src)

print("OK -- part 1 (state/load/mutate functions) applied")
