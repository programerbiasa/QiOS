class ChatMessageModel {
  final int id;
  final int userId;
  final String senderName;
  final String senderRole;
  final String message;
  final String createdAt;

  const ChatMessageModel({
    required this.id,
    required this.userId,
    required this.senderName,
    required this.senderRole,
    required this.message,
    required this.createdAt,
  });

  bool isMe(int currentUserId) => userId == currentUserId;

  bool get isAdmin => senderRole.toLowerCase() == 'admin';

  factory ChatMessageModel.fromJson(Map<String, dynamic> json) {
    return ChatMessageModel(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      userId: json['user_id'] is int ? json['user_id'] : int.tryParse(json['user_id'].toString()) ?? 0,
      senderName: json['sender_name']?.toString() ?? '',
      senderRole: json['sender_role']?.toString() ?? 'kasir',
      message: json['message']?.toString() ?? '',
      createdAt: json['created_at']?.toString() ?? '',
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'user_id': userId,
      'sender_name': senderName,
      'sender_role': senderRole,
      'message': message,
      'created_at': createdAt,
    };
  }
}
