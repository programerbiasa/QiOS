import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../../../data/models/chat_message_model.dart';
import '../../../../data/models/user_model.dart';
import '../../../../data/services/api_service.dart';

class ChatDialog extends StatefulWidget {
  final UserModel currentUser;

  const ChatDialog({
    super.key,
    required this.currentUser,
  });

  @override
  State<ChatDialog> createState() => _ChatDialogState();
}

class _ChatDialogState extends State<ChatDialog> {
  final ApiService _apiService = ApiService();
  final TextEditingController _msgController = TextEditingController();
  final ScrollController _scrollController = ScrollController();
  final FocusNode _focusNode = FocusNode();

  final List<ChatMessageModel> _messages = [];
  Timer? _pollingTimer;
  int _lastMessageId = 0;
  bool _isLoading = true;
  bool _isSending = false;
  String _onlineStatusText = 'Memeriksa status admin...';

  @override
  void initState() {
    super.initState();
    _loadInitialHistory();
  }

  @override
  void dispose() {
    _pollingTimer?.cancel();
    _msgController.dispose();
    _scrollController.dispose();
    _focusNode.dispose();
    super.dispose();
  }

  Future<void> _loadInitialHistory() async {
    setState(() => _isLoading = true);
    final result = await _apiService.getChatHistory(userId: widget.currentUser.id);
    if (!mounted) return;

    final List<ChatMessageModel> msgs = result['messages'] ?? [];
    final List onlineUsers = result['online_users'] ?? [];

    setState(() {
      _messages.clear();
      _messages.addAll(msgs);
      if (_messages.isNotEmpty) {
        _lastMessageId = _messages.last.id;
      }
      _updateOnlineStatus(onlineUsers);
      _isLoading = false;
    });

    _scrollToBottom();
    _startPolling();
  }

  void _updateOnlineStatus(List onlineUsers) {
    bool hasOnlineAdmin = false;
    for (var u in onlineUsers) {
      if (u is Map && (u['role'] == 'admin' || u['role'] == 'superadmin')) {
        if (u['is_online'] == 1 || u['is_online'] == true) {
          hasOnlineAdmin = true;
          break;
        }
      }
    }

    if (hasOnlineAdmin) {
      _onlineStatusText = 'Admin Sedang Online';
    } else {
      _onlineStatusText = 'Admin Terhubung (Web Browser)';
    }
  }

  void _startPolling() {
    _pollingTimer?.cancel();
    _pollingTimer = Timer.periodic(const Duration(seconds: 3), (_) async {
      if (!mounted) return;
      final result = await _apiService.pollChatMessagesResult(
        lastId: _lastMessageId,
        userId: widget.currentUser.id,
      );

      if (!mounted) return;

      final int maxId = result['max_id'] is int ? result['max_id'] : (int.tryParse('${result['max_id']}') ?? 0);
      final List<ChatMessageModel> newMsgs = (result['messages'] as List<ChatMessageModel>?) ?? [];
      final List onlineUsers = (result['online_users'] as List?) ?? [];

      _updateOnlineStatus(onlineUsers);

      // Jika chat dibersihkan oleh admin/kasir lain
      if (maxId < _lastMessageId) {
        setState(() {
          _messages.clear();
          _lastMessageId = maxId;
        });
        return;
      }

      if (newMsgs.isEmpty) return;

      bool hasIncomingAdmin = false;
      setState(() {
        for (var m in newMsgs) {
          if (!_messages.any((existing) => existing.id == m.id)) {
            _messages.add(m);
            if (m.isAdmin || !m.isMe(widget.currentUser.id)) {
              hasIncomingAdmin = true;
            }
            if (m.id > _lastMessageId) {
              _lastMessageId = m.id;
            }
          }
        }
      });
      if (hasIncomingAdmin) {
        SystemSound.play(SystemSoundType.alert);
      }
      _scrollToBottom();
    });
  }

  void _scrollToBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollController.hasClients) {
        _scrollController.animateTo(
          _scrollController.position.maxScrollExtent,
          duration: const Duration(milliseconds: 250),
          curve: Curves.easeOut,
        );
      }
    });
  }

  Future<void> _sendMessage() async {
    final text = _msgController.text.trim();
    if (text.isEmpty || _isSending) return;

    setState(() => _isSending = true);
    _msgController.clear();

    final success = await _apiService.sendChatMessage(
      userId: widget.currentUser.id,
      message: text,
    );

    if (mounted) {
      setState(() => _isSending = false);
      if (success) {
        // Fetch new messages immediately
        final newMsgs = await _apiService.pollChatMessages(
          lastId: _lastMessageId,
          userId: widget.currentUser.id,
        );
        if (mounted && newMsgs.isNotEmpty) {
          setState(() {
            for (var m in newMsgs) {
              if (!_messages.any((existing) => existing.id == m.id)) {
                _messages.add(m);
                if (m.id > _lastMessageId) {
                  _lastMessageId = m.id;
                }
              }
            }
          });
          _scrollToBottom();
        }
      }
      _focusNode.requestFocus();
    }
  }

Future<void> _confirmClearChat() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF1E293B),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Row(
          children: [
            Icon(Icons.delete_sweep_rounded, color: Color(0xFFEF4444)),
            SizedBox(width: 8),
            Text(
              'Bersihkan Chat?',
              style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
            ),
          ],
        ),
        content: const Text(
          'Apakah Anda yakin ingin menghapus seluruh riwayat chat? Percakapan akan dibersihkan untuk kasir dan admin.',
          style: TextStyle(color: Color(0xFFCBD5E1), fontSize: 13),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Batal', style: TextStyle(color: Color(0xFF94A3B8))),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFFEF4444),
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Ya, Bersihkan'),
          ),
        ],
      ),
    );

    if (confirm == true) {
      final success = await _apiService.clearChat(userId: widget.currentUser.id);
      if (mounted) {
        if (success) {
          setState(() {
            _messages.clear();
            _lastMessageId = 0;
          });
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Riwayat chat berhasil dibersihkan!'),
              backgroundColor: Color(0xFF10B981),
              duration: Duration(seconds: 2),
            ),
          );
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Gagal membersihkan riwayat chat.'),
              backgroundColor: Color(0xFFEF4444),
            ),
          );
        }
      }
    }
  }

  String _formatTime(String raw) {

    if (raw.length >= 16) {
      return raw.substring(11, 16);
    }
    return raw;
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      backgroundColor: Colors.transparent,
      insetPadding: const EdgeInsets.symmetric(horizontal: 24, vertical: 24),
      child: Container(
        width: 440,
        height: 600,
        decoration: BoxDecoration(
          color: const Color(0xFF0F172A),
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: const Color(0xFF334155)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.65),
              blurRadius: 35,
              offset: const Offset(0, 15),
            ),
          ],
        ),
        child: Column(
          children: [
            // Header
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
              decoration: const BoxDecoration(
                color: Color(0xFF1E293B),
                borderRadius: BorderRadius.only(
                  topLeft: Radius.circular(20),
                  topRight: Radius.circular(20),
                ),
                border: Border(
                  bottom: BorderSide(color: Color(0xFF334155)),
                ),
              ),
              child: Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: const Color(0xFF0284C7).withValues(alpha: 0.2),
                      shape: BoxShape.circle,
                    ),
                    child: const Icon(
                      Icons.support_agent_rounded,
                      color: Color(0xFF38BDF8),
                      size: 22,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'Komunikasi Kasir & Admin',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 14,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Row(
                          children: [
                            Container(
                              width: 8,
                              height: 8,
                              decoration: const BoxDecoration(
                                color: Color(0xFF10B981),
                                shape: BoxShape.circle,
                              ),
                            ),
                            const SizedBox(width: 6),
                            Text(
                              _onlineStatusText,
                              style: const TextStyle(
                                color: Color(0xFF94A3B8),
                                fontSize: 11,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  IconButton(
                    onPressed: _confirmClearChat,
                    icon: const Icon(Icons.delete_sweep_outlined, color: Color(0xFFEF4444), size: 20),
                    tooltip: 'Bersihkan Riwayat Chat',
                    splashRadius: 20,
                  ),
                  IconButton(
                    onPressed: () => Navigator.of(context).pop(),
                    icon: const Icon(Icons.close, color: Color(0xFF94A3B8), size: 20),
                    tooltip: 'Tutup Chat',
                    splashRadius: 20,
                  ),
                ],
              ),
            ),

            // Body Messages
            Expanded(
              child: _isLoading
                  ? const Center(
                      child: CircularProgressIndicator(color: Color(0xFF38BDF8)),
                    )
                  : _messages.isEmpty
                      ? Center(
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(
                                Icons.chat_bubble_outline_rounded,
                                size: 48,
                                color: const Color(0xFF475569).withValues(alpha: 0.5),
                              ),
                              const SizedBox(height: 12),
                              const Text(
                                'Belum ada obrolan.',
                                style: TextStyle(
                                  color: Color(0xFF94A3B8),
                                  fontSize: 13,
                                  fontWeight: FontWeight.w500,
                                ),
                              ),
                              const SizedBox(height: 4),
                              const Text(
                                'Kirim pesan pertama ke admin di sini.',
                                style: TextStyle(
                                  color: Color(0xFF64748B),
                                  fontSize: 11,
                                ),
                              ),
                            ],
                          ),
                        )
                      : ListView.builder(
                          controller: _scrollController,
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                          itemCount: _messages.length,
                          itemBuilder: (context, index) {
                            final msg = _messages[index];
                            final isMe = msg.isMe(widget.currentUser.id);

                            return Align(
                              alignment: isMe ? Alignment.centerRight : Alignment.centerLeft,
                              child: Container(
                                margin: const EdgeInsets.symmetric(vertical: 4),
                                constraints: BoxConstraints(
                                  maxWidth: MediaQuery.of(context).size.width * 0.7,
                                ),
                                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                                decoration: BoxDecoration(
                                  color: isMe
                                      ? const Color(0xFF0284C7)
                                      : const Color(0xFF1E293B),
                                  borderRadius: BorderRadius.only(
                                    topLeft: const Radius.circular(14),
                                    topRight: const Radius.circular(14),
                                    bottomLeft: Radius.circular(isMe ? 14 : 2),
                                    bottomRight: Radius.circular(isMe ? 2 : 14),
                                  ),
                                  border: Border.all(
                                    color: isMe
                                        ? const Color(0xFF38BDF8)
                                        : const Color(0xFF334155),
                                    width: 1,
                                  ),
                                ),
                                child: Column(
                                  crossAxisAlignment: isMe
                                      ? CrossAxisAlignment.end
                                      : CrossAxisAlignment.start,
                                  children: [
                                    if (!isMe) ...[
                                      Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          Text(
                                            msg.senderName.isNotEmpty ? msg.senderName : 'Admin',
                                            style: const TextStyle(
                                              color: Color(0xFF38BDF8),
                                              fontSize: 11,
                                              fontWeight: FontWeight.bold,
                                            ),
                                          ),
                                          const SizedBox(width: 6),
                                          Container(
                                            padding: const EdgeInsets.symmetric(
                                              horizontal: 5,
                                              vertical: 1,
                                            ),
                                            decoration: BoxDecoration(
                                              color: msg.isAdmin
                                                  ? const Color(0xFFD97706)
                                                  : const Color(0xFF0D9488),
                                              borderRadius: BorderRadius.circular(4),
                                            ),
                                            child: Text(
                                              msg.senderRole.toUpperCase(),
                                              style: const TextStyle(
                                                color: Colors.white,
                                                fontSize: 8,
                                                fontWeight: FontWeight.bold,
                                              ),
                                            ),
                                          ),
                                        ],
                                      ),
                                      const SizedBox(height: 3),
                                    ],
                                    Text(
                                      msg.message,
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontSize: 13,
                                        height: 1.3,
                                      ),
                                    ),
                                    const SizedBox(height: 3),
                                    Text(
                                      _formatTime(msg.createdAt),
                                      style: TextStyle(
                                        color: isMe
                                            ? Colors.white.withValues(alpha: 0.7)
                                            : const Color(0xFF94A3B8),
                                        fontSize: 9,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
            ),

            // Input Bar
            Container(
              padding: const EdgeInsets.all(12),
              decoration: const BoxDecoration(
                color: Color(0xFF1E293B),
                borderRadius: BorderRadius.only(
                  bottomLeft: Radius.circular(20),
                  bottomRight: Radius.circular(20),
                ),
                border: Border(
                  top: BorderSide(color: Color(0xFF334155)),
                ),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _msgController,
                      focusNode: _focusNode,
                      style: const TextStyle(color: Colors.white, fontSize: 13),
                      textInputAction: TextInputAction.send,
                      onSubmitted: (_) => _sendMessage(),
                      decoration: InputDecoration(
                        hintText: 'Tulis pesan ke admin...',
                        hintStyle: const TextStyle(color: Color(0xFF64748B), fontSize: 12),
                        filled: true,
                        fillColor: const Color(0xFF0F172A),
                        isDense: true,
                        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(20),
                          borderSide: const BorderSide(color: Color(0xFF334155)),
                        ),
                        enabledBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(20),
                          borderSide: const BorderSide(color: Color(0xFF334155)),
                        ),
                        focusedBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(20),
                          borderSide: const BorderSide(color: Color(0xFF38BDF8)),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Container(
                    decoration: const BoxDecoration(
                      color: Color(0xFF0284C7),
                      shape: BoxShape.circle,
                    ),
                    child: IconButton(
                      onPressed: _isSending ? null : _sendMessage,
                      icon: _isSending
                          ? const SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: Colors.white,
                              ),
                            )
                          : const Icon(
                              Icons.send_rounded,
                              color: Colors.white,
                              size: 18,
                            ),
                      tooltip: 'Kirim Pesan',
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
