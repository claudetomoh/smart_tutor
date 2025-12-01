import { useEffect, useMemo, useState } from 'react';
import '../../css/dashboard.css';

const fallbackContacts = [
  { id: 41, label: 'Amina Patel (amina@studentmail.com)' },
  { id: 52, label: 'Leo Ramirez (leo.ramirez@studentmail.com)' },
  { id: 63, label: 'Dr. S. Rivera (s.rivera@smartutor.org)' }
];

const fallbackThreads = [
  {
    id: 101,
    subject: 'STEM coaching sprint',
    participants: 'Amina Patel',
    last_message_at: '2025-11-20T14:32:00Z',
    last_message_preview: 'Sharing the problem set now — thanks!',
    unread_count: 2
  },
  {
    id: 102,
    subject: 'Weekly availability',
    participants: 'Leo Ramirez',
    last_message_at: '2025-11-18T08:05:00Z',
    last_message_preview: 'Can we shift Thursday to 5pm?',
    unread_count: 0
  }
];

const fallbackMessages = {
  101: [
    {
      id: 7001,
      sender_id: 999,
      sender_name: 'You',
      message_text: 'I will review the robotics prompt tonight.',
      created_at: '2025-11-20T11:15:00Z'
    },
    {
      id: 7002,
      sender_id: 41,
      sender_name: 'Amina Patel',
      message_text: 'Sharing the problem set now — thanks!',
      created_at: '2025-11-20T14:32:00Z'
    }
  ],
  102: [
    {
      id: 7010,
      sender_id: 52,
      sender_name: 'Leo Ramirez',
      message_text: 'Can we shift Thursday to 5pm?',
      created_at: '2025-11-18T08:05:00Z'
    }
  ]
};

function buildMessagePayload(text, senderId = 999) {
  return {
    id: Number(Date.now()),
    sender_id: senderId,
    sender_name: senderId === 999 ? 'You' : 'Contact',
    message_text: text,
    created_at: new Date().toISOString()
  };
}

export default function MessagesPanel({ apiEndpoint = '/api/messages.php', contacts = fallbackContacts }) {
  const [threads, setThreads] = useState(fallbackThreads);
  const [messages, setMessages] = useState(fallbackMessages[fallbackThreads[0].id] || []);
  const [selectedThreadId, setSelectedThreadId] = useState(fallbackThreads[0].id);
  const [status, setStatus] = useState('');
  const [loadingThreads, setLoadingThreads] = useState(false);
  const [useMockData, setUseMockData] = useState(true);
  const [newRecipient, setNewRecipient] = useState('');
  const [newMessage, setNewMessage] = useState('');
  const [replyMessage, setReplyMessage] = useState('');

  const totalUnread = useMemo(() => threads.reduce((sum, thread) => sum + (thread.unread_count || 0), 0), [threads]);

  async function fetchThreads() {
    setLoadingThreads(true);
    try {
      const response = await fetch(apiEndpoint, { credentials: 'include' });
      if (!response.ok) {
        throw new Error('Requires authentication');
      }
      const payload = await response.json();
      setThreads(payload.data || []);
      setUseMockData(false);
      if ((payload.data || []).length > 0) {
        const firstId = payload.data[0].id;
        await loadThread(firstId, payload.data[0]);
      }
      setStatus('');
    } catch (error) {
      setThreads(fallbackThreads);
      setMessages(fallbackMessages[fallbackThreads[0].id] || []);
      setSelectedThreadId(fallbackThreads[0].id);
      setUseMockData(true);
      setStatus('Showing sample data. Sign in to sync live conversations.');
    } finally {
      setLoadingThreads(false);
    }
  }

  useEffect(() => {
    // Attempt to hydrate with real data when authenticated; fallback silently otherwise.
    fetchThreads();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function loadThread(threadId, threadMeta) {
    setSelectedThreadId(threadId);
    if (useMockData) {
      setMessages(fallbackMessages[threadId] || []);
      return;
    }
    try {
      setStatus('Loading conversation…');
      const response = await fetch(`${apiEndpoint}?thread_id=${encodeURIComponent(threadId)}`, { credentials: 'include' });
      if (!response.ok) {
        throw new Error('Unable to load conversation');
      }
      const payload = await response.json();
      setMessages(payload.data || []);
      setStatus('');
    } catch (error) {
      console.error(error);
      setStatus('Unable to load conversation.');
      if (threadMeta && fallbackMessages[threadId]) {
        setMessages(fallbackMessages[threadId]);
      }
    }
  }

  const handleThreadSelect = (thread) => {
    loadThread(thread.id, thread);
  };

  const handleReplySubmit = async (event) => {
    event.preventDefault();
    const trimmed = replyMessage.trim();
    if (!trimmed || !selectedThreadId) {
      return;
    }

    if (useMockData) {
      const updated = [...(messages || []), buildMessagePayload(trimmed)];
      fallbackMessages[selectedThreadId] = updated;
      setMessages(updated);
      setReplyMessage('');
      setStatus('Message recorded locally.');
      return;
    }

    try {
      setStatus('Sending message…');
      const response = await fetch(apiEndpoint, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'send', thread_id: selectedThreadId, message: trimmed })
      });
      if (!response.ok) {
        throw new Error('Send failed');
      }
      setReplyMessage('');
      await loadThread(selectedThreadId);
      fetchThreads();
      setStatus('Message sent.');
    } catch (error) {
      console.error(error);
      setStatus('Unable to send message.');
    }
  };

  const handleNewConversation = async (event) => {
    event.preventDefault();
    const trimmedMessage = newMessage.trim();
    const recipientId = Number(newRecipient);
    if (!recipientId || !trimmedMessage) {
      return;
    }

    if (useMockData) {
      const newThreadId = Number(Date.now());
      const contactLabel = contacts.find((contact) => contact.id === recipientId)?.label || 'New contact';
      const newThread = {
        id: newThreadId,
        subject: 'New conversation',
        participants: contactLabel,
        last_message_at: new Date().toISOString(),
        last_message_preview: trimmedMessage,
        unread_count: 0
      };
      const mockMsg = buildMessagePayload(trimmedMessage);
      fallbackMessages[newThreadId] = [mockMsg];
      setThreads([newThread, ...threads]);
      setMessages([mockMsg]);
      setSelectedThreadId(newThreadId);
      setNewRecipient('');
      setNewMessage('');
      setStatus('Conversation drafted. Sign in to sync.');
      return;
    }

    try {
      setStatus('Starting conversation…');
      const response = await fetch(apiEndpoint, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'send', recipient_id: recipientId, message: trimmedMessage })
      });
      const payload = await response.json();
      if (!response.ok || payload.success === false) {
        throw new Error(payload.message || 'Unable to start chat');
      }
      setNewRecipient('');
      setNewMessage('');
      await fetchThreads();
      if (payload.data?.thread_id) {
        loadThread(payload.data.thread_id);
      }
      setStatus('Conversation started.');
    } catch (error) {
      console.error(error);
      setStatus('Unable to start conversation.');
    }
  };

  return (
    <section className="dashboard-card messaging-card" aria-label="Messaging showcase">
      <header className="notification-header">
        <div>
          <h3>Direct messaging</h3>
          <p className="metric-note">Live coordination between tutors, students, and support.</p>
        </div>
        <div className="notification-actions">
          <span className="message-unread-indicator">
            <strong>{totalUnread}</strong> unread
          </span>
          <button type="button" className="btn btn-sm btn-outline" onClick={fetchThreads} disabled={loadingThreads}>
            {loadingThreads ? 'Refreshing…' : 'Refresh'}
          </button>
        </div>
      </header>
      {status ? <p className="metric-note" role="status" aria-live="polite">{status}</p> : null}
      {useMockData ? (
        <p className="message-empty-hint">
          You are previewing static demo data. Log in to see real-time conversations.
        </p>
      ) : null}
      <div className="messages-layout">
        <aside className="message-thread-panel">
          <h4 className="section-subtitle">Conversations</h4>
          <ul className="message-thread-list">
            {threads.length === 0 ? (
              <li className="placeholder">No conversations yet.</li>
            ) : (
              threads.map((thread) => (
                <li
                  key={thread.id}
                  className={`thread-item ${thread.id === selectedThreadId ? 'is-active' : ''} ${thread.unread_count ? 'thread-item--unread' : ''}`}
                  onClick={() => handleThreadSelect(thread)}
                >
                  <div className="thread-header-row">
                    <p className="thread-subject">{thread.subject || 'Conversation'}</p>
                    {thread.unread_count ? (
                      <span className="thread-unread-badge">{thread.unread_count > 9 ? '9+' : thread.unread_count}</span>
                    ) : null}
                  </div>
                  <p className="thread-meta">
                    {thread.participants}
                    {thread.last_message_at ? ` • ${new Date(thread.last_message_at).toLocaleString()}` : ''}
                  </p>
                  {thread.last_message_preview ? <p className="thread-meta">{thread.last_message_preview}</p> : null}
                </li>
              ))
            )}
          </ul>
        </aside>
        <div className="message-panel">
          <div className="message-log">
            {messages.length === 0 ? (
              <p className="placeholder">Select a thread to preview messages.</p>
            ) : (
              messages.map((message) => (
                <div
                  key={message.id}
                  className={`message-bubble ${message.sender_id === 999 ? 'message-bubble--own' : ''}`}
                >
                  <p className="message-bubble__meta">
                    {message.sender_name || 'Participant'} • {new Date(message.created_at).toLocaleString()}
                  </p>
                  <p className="message-bubble__body">{message.message_text}</p>
                </div>
              ))
            )}
          </div>
          <form className="message-form" onSubmit={handleReplySubmit}>
            <label>
              <span>Reply</span>
              <textarea
                rows="2"
                value={replyMessage}
                onChange={(event) => setReplyMessage(event.target.value)}
                placeholder="Write a quick reply…"
              />
            </label>
            <button type="submit" className="btn btn-primary" disabled={!replyMessage.trim()}>
              Send reply
            </button>
          </form>
          <div className="message-divider">Start a new conversation</div>
          <form className="message-form" onSubmit={handleNewConversation}>
            <label>
              <span>Recipient</span>
              <select value={newRecipient} onChange={(event) => setNewRecipient(event.target.value)} required>
                <option value="">Select a contact…</option>
                {contacts.map((contact) => (
                  <option key={contact.id} value={contact.id}>
                    {contact.label}
                  </option>
                ))}
              </select>
            </label>
            <label>
              <span>Message</span>
              <textarea
                rows="2"
                value={newMessage}
                onChange={(event) => setNewMessage(event.target.value)}
                placeholder="Say hello or confirm next steps…"
              />
            </label>
            <button type="submit" className="btn btn-outline" disabled={!newMessage.trim() || !newRecipient}>
              Send new message
            </button>
          </form>
        </div>
      </div>
    </section>
  );
}
