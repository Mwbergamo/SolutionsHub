/* Help Center content, part 4: Client Finance and Marketing. See help-content.js for the field list. */
(function () {
  window.HELP_SECTIONS = window.HELP_SECTIONS || [];
  window.HELP_SECTIONS.push(
    {
      id: 'client-finance', title: 'Finance: Client Finance (restricted)',
      intro: 'A per-customer financial dashboard synced live from ConnectWise, plus portfolio-wide revenue gauges. Only people on the same access list as Commissions can open it.',
      articles: [
        { id: 'cf-access', title: 'Who can open Client Finance',
          def: 'Client Finance is limited to the same 4 people as Commissions, enforced on the server.',
          where: 'Hub home > Finance > Client Finance (the Finance menu item only shows if you have access).', link: { href: '../client-finance/', label: 'Open Client Finance' },
          why: 'Keeps a customer’s financial detail private.', steps: ['Sign in, click Finance, then click Client Finance.'], notes: ['If you do not have access you will see a message to ask Michael Bergamo.'],
          added: '2026-10-16', updated: '2026-10-16', kw: ['access', 'permission', 'restricted'] },
        { id: 'cf-home', title: 'Searching a customer and the 5 sections',
          def: 'Search for a customer, then see 5 cards once each syncs: Standard Invoices, Product Sales, Agreement Invoicing, Credits and Opportunities.',
          where: 'Client Finance home screen.', link: { href: '../client-finance/', label: 'Open Client Finance' },
          why: 'Pull up a customer’s real financial picture live, during a client meeting.',
          steps: ['Search by company or contact name and pick the customer.', 'The dashboard auto-syncs from ConnectWise on first open.', 'Click a section card once it lights up to see its chart and line detail.'],
          notes: ['Standard Invoices is T&M billing with a 6-month trend.', 'Product Sales is Sales Order dollars year over year, with line-level detail (what was sold, cost, price, margin, and which Sales Order/Opportunity it came from).', 'Agreement Invoicing shows every agreement’s own trend and addition-level detail.', 'Credits are Credit Memo invoices with the same 6-month trend.', 'Opportunities flags Computer or Networking hardware sold 3+ years ago; Push to Relationships creates a to-do there for a proactive upgrade conversation, with a link back to this exact dashboard.'],
          added: '2026-10-16', updated: '2026-10-16', kw: ['sync', 'invoices', 'agreements', 'credits', 'opportunities', 'customer'] },
        { id: 'cf-gauges', title: 'Front-page gauges: Recurring, Annual, Sales Order Revenue',
          def: 'Three portfolio-wide gauges under customer search — Recurring Revenue (last closed month), Annual Revenue (year to date, net of credits), Sales Order Revenue (year to date) — each opening a sortable popover you can expand line by line.',
          where: 'Client Finance home screen, under the search box.', link: { href: '../client-finance/', label: 'Open Client Finance' },
          why: 'See how the whole book is doing without opening one customer at a time.',
          steps: ['Click a gauge card to open its popover.', 'Rows are sorted by total, highest first.', 'Click a row to expand its product-line detail.', 'Click Sync all customers now to refresh every customer’s data (anyone with Client Finance access can run this).'],
          notes: ['Recurring Revenue is real invoiced Agreement-category dollars for one month — not a smoothed estimate, so a customer billed quarterly or annually shows as a spike in whichever month that invoice lands.', 'Annual Revenue sums all invoice types for the current calendar year and subtracts credits.', 'Sales Order Revenue uses the real ConnectWise Sales Order record, not a re-derived invoice total, so an order spanning more than one invoice is still counted once.', 'Each gauge shows a coverage/freshness note — a customer nobody has synced yet (via opening their dashboard or a bulk sync) will not be reflected until it is.'],
          added: '2026-10-21', updated: '2026-11-06', kw: ['gauges', 'revenue', 'mrr', 'recurring', 'annual', 'sales order', 'sync', 'bulk sync'] }
      ]
    },
    {
      id: 'marketing', title: 'Marketing',
      intro: 'A filing cabinet of marketing collateral, organized by service pillar, with upload/download/search, a recoverable Trash, and sending a document to a company contact by email.',
      articles: [
        { id: 'mkt-filing-cabinet', title: 'Pillars, folders and documents',
          def: '6 pillar tabs — IT Services, Data Center Services, Premise Security Services, Data Cabling Services, Voice over IP Services, and General / Company-Wide — each with folders, subfolders and documents you can sort by name, type or date, or search across every folder.',
          where: 'Hub home > Marketing.', link: { href: '../marketing/', label: 'Open Marketing' },
          why: 'One organized place for every brochure, rate sheet and proposal template CodeBlue uses, instead of scattered email attachments.',
          steps: ['Click a pillar tab, then a folder.', 'Click + New Folder to add a subfolder, or the pencil icon to rename one.', 'Drag a file onto the page, or click Upload, to add a document (up to 100 MB).', 'Click a column header to sort by Name, Type or Date.', 'Use the search box to find a file by name across every folder.', 'Click Download on a row to save a copy.'],
          notes: ['Every signed-in rep can create, rename and upload — there is no restricted-access list for Marketing.', 'Supported types: PDF, Word, Excel, PowerPoint, images, Visio, OpenDocument formats, csv/txt/rtf and zip.'],
          added: '2026-11-12', updated: '2026-11-12', kw: ['filing cabinet', 'folders', 'pillars', 'upload', 'download', 'search', 'sort'] },
        { id: 'mkt-trash', title: 'Deleting folders and documents (Trash)',
          def: 'Deleting a folder or document moves it to Trash instead of removing it right away; a separate Purge action is what actually, permanently deletes it.',
          where: 'Marketing, the trash-can icon on a folder or document, and the Trash view.', link: { href: '../marketing/', label: 'Open Marketing' },
          why: 'Makes it safe to clean up folders and files routinely without one wrong click losing something for good.',
          steps: ['Click the trash-can icon on a folder or document to move it to Trash (deleting a folder takes everything inside it with it).', 'Open Trash to see everything removed.', 'Click Restore to bring it back.', 'Click Purge to permanently delete it — this cannot be undone.'],
          notes: ['Restoring a folder brings back everything that was inside it when it was trashed.', 'Purge is the only action here that is truly permanent.'],
          added: '2026-11-12', updated: '2026-11-12', kw: ['delete', 'trash', 'restore', 'purge', 'remove'] },
        { id: 'mkt-send-email', title: 'Send a document to a company contact',
          def: 'From any document, search for a company, pick one of its contacts who has an email on file, add an optional note, and send it by email.',
          where: 'Marketing, the Send (envelope) icon on a document row.', link: { href: '../marketing/', label: 'Open Marketing' },
          why: 'Get a brochure or rate sheet straight to a prospect or customer contact without leaving Marketing or attaching it to a separate email yourself.',
          steps: ['Click the Send icon on a document.', 'Search for the company by name.', 'Pick a contact — contacts with no email on file are shown but cannot be picked.', 'Add an optional note, then Send.'],
          notes: ['The email comes from CodeBlue’s shared mail account, but replies go back to you — Reply-To is set to the sending rep.', 'Documents over 3 MB cannot be emailed (Microsoft’s own limit on the mail account) — download and share those another way instead.', 'Company/contact search is not limited by territory — any rep can send collateral to any company.'],
          added: '2026-11-12', updated: '2026-11-12', kw: ['send', 'email', 'contact', 'company', 'attach'] },
        { id: 'mkt-send-history', title: 'Send history (what has already been sent)',
          def: 'A per-company list of every document already sent from Marketing — filename, contact, rep and date/time.',
          where: 'Marketing, the Send dialog’s history view for a company.', link: { href: '../marketing/', label: 'Open Marketing' },
          why: 'Know what a company has already received before sending something again.',
          steps: ['Open the Send dialog and search for the company.', 'Its send history shows underneath, newest first.'],
          notes: ['History keeps the filename, company and contact as they were at send time, so it still reads correctly even if that document is later renamed or deleted.'],
          added: '2026-11-12', updated: '2026-11-12', kw: ['history', 'sent', 'log', 'tracking'] }
      ]
    }
  );
})();
