<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
</head>
<body>
  <style>
    .lunar-loader {
      position: absolute;
      top: 50%;
      left: 50%;
      width: 200px;
      height: 200px;
      margin-top: -100px; /* half the height */
      margin-left: -100px; /* half the width */
      border: 16px solid #f3f3f3;
      border-top: 16px solid #00CB39;
      border-radius: 50%;
      animation: spin 2s linear infinite;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
  </style>
  
  <div class="lunar-loader"></div>
</body>
</html>
