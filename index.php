<?php
// Start PHP before any HTML or whitespace
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Collect form inputs
  $username = $_POST['username'] ?? '';
  $password = $_POST['password'] ?? '';

  // Define credentials
  $supply_username = 'supply';
  $supply_password = 'supply123';

  $senior_username = 'senior';
  $senior_password = 'senior123';

  // Validate credentials
  if ($username === $supply_username && $password === $supply_password) {
    header('Location: supplychainmanager.php');
    exit;
  } elseif ($username === $senior_username && $password === $senior_password) {
    header('Location: seniormanager.php');
    exit;
  } else {
    echo "<script>alert('Invalid username or password'); window.location.href='index.php';</script>";
    exit;
  }
}
?>

<html>
  <head>
    <title>Palantir</title>
  <style>
    .header {
      background-color: #141414;
      text-align: center;
      position: fixed;
      width: 100%;
      color: whitesmoke;
      display: flex;
      flex-direction: row;
      align-items: center;
      justify-content: space-between;
    }

    .dummy {
      width: 2em;
      aspect-ratio: 1;
      margin-right: 1rem;
    }

    .header h1{
      font-weight: normal;
    }

    h2{
      font-weight: normal;
    }

    .headerspacer {
      margin-bottom: 1rem;
      color: clear;
      background-color: clear;
      padding-top: 0.67em;
      padding-bottom: 0.67em;
    }

    body {
      margin: 0;
      font-size: medium;
      font-family: Verdana, Geneva, Tahoma, sans-serif;
      background-color: whitesmoke;
    }
    
    .content {
      display: flex;
      flex-direction: column;
      align-items: center;
      padding-bottom: 4rem;
    }
    
    .photobox {
      width: 100%;
      display: flex;
      flex-direction: row;
      column-gap: 1rem;
      justify-content: space-around;
      padding-top: 1rem;
      padding-bottom: 7rem;
    }

    .photoboxwrapper {
      height: 20rem;
      width: 90%;
      background-color: #3f3f3f;
      border-radius: 16px 8px 16px 8px;
      box-shadow: 0px 4px 3px 1px #0a0a0a;
      text-align: center;
      color:whitesmoke;
    }

    .square{
      aspect-ratio: 1;
      width: 90%;
      background-color: whitesmoke;
      transition: width 0.2s, height 0.2s;
      transition-timing-function: ease-in-out;
      border-radius: 12px 6px 12px 6px;
    }

    .square:hover {
      width: 100%;
    } 

    .squarewrapper {
      aspect-ratio: 1;
      width: 8rem;
      display: flex;
      flex-direction: column;
      justify-content: space-around;
      align-items: center;
    }

    .card {
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      align-items: center;
      text-align: center;
      color: whitesmoke;
    }

    .card p {
      margin-bottom: 0;
    }

    underline {
      border-top: 1px solid whitesmoke;
      width: 0;
      transition: width 0.2s;
      transition-timing-function: ease-in;
      display: block;
    }

    .card:hover underline {
      width: 90%;
    }

    .circle {
      position: absolute;
      display: flex;
      flex-direction: column;
      justify-content: center;
      right: 1rem;
      bottom: 3rem;
      border-radius: 50%;
      background-color: whitesmoke;
      aspect-ratio: 1;
      width: 2rem;
      text-align: center;
      padding: .2rem;
      box-shadow: 0 0 3px 0px #0a0a0a;
      align-items: center;
    }

    .circle:hover{
      box-shadow: 0 0 2px 1px #0a0a0a;
    } 

    .circle:hover underline {
      width: 60%;
      border-top: 1px solid black;
    }

    .header img {
      height: 2em;
      filter: invert(95%);
      margin-left: 1rem;
    }

    #logincontainer {
      margin-top: 2rem;
      background-color: #3f3f3f;
      padding: 1rem 3rem;
      border-radius: 16px 8px 16px 8px;
      box-shadow: 0px 4px 3px 1px #0a0a0a;
      text-align: center;
      color:whitesmoke;
      width: 320px;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    #logincontainer h1 {
      margin-bottom: 2rem;
      font-weight: 500;
      letter-spacing: 1px;
    }

    #logincontainer h2 {     
      padding-bottom: 1rem; 
    }

    .login-input {
      width: 100%;
      padding: 0.8rem 1rem;
      margin-bottom: 1rem;
      border: none;
      border-radius: 8px;
      background-color: whitesmoke;
      color: white;
      font-size: 1rem;
      outline: none;
      transition: all 0.2s ease;
    }

    .login-input:focus {
      background-color: #141414;
      box-shadow: 0 0 0 2px #daaa00;
    }

    button.login_input {
      width: 100%;
      padding: 0.8rem 1rem;
      border: none;
      border-radius: 8px;
      background-color: #cfb991;
      color: white;
      font-weight: 600;
      font-size: 1rem;
      cursor: pointer;
      transition: background-color 0.2s ease, transform 0.1s ease;
    }

    button.login_input:hover {
      background-color: #daaa00;
    }

    button.login_input:active {
      transform: scale(0.98);
    }

    .footer {
      background-color: #000;         
      color: whitesmoke;              
      text-align: center;
      padding: 0.5rem 0;
      font-size: 0.9rem;
      position: fixed;                
      bottom: 0;
      left: 0;
      width: 100%;
    }
  </style>
  </head>

  <body>
  <div class="header">
    <img src="https://assets.streamlinehq.com/image/private/w_300,h_300,ar_1/f_auto/v1/icons/logos/palantir-xdp5vzpsdiuov68zwbgn.png/palantir-4vc1iawq4sqw5r2jh1n7cr.png?_a=DATAg1AAZAA0">
    <h1>Palantir</h1>
    <div class="dummy">

    </div>
  </div>

  <div class="headerspacer">
    <h1>Palantir</h1>
  </div>
    <div class="content">
      <div class="photoboxwrapper">
        <h2>Meet the Team</h2>
        <div class="photobox">
          <div class="card">
            <div class="squarewrapper">
              <div class="square">
              </div>
            </div>
            <p>Jake Getson</p>
            <underline></underline>
          </div>
          <div class="card">
            <div class="squarewrapper">
              <div class="square">
              </div>
            </div>
            <p>Alejandro Melendez</p>
            <underline></underline>
          </div>
          <div class="card">
            <div class="squarewrapper">
              <div class="square">
              </div>
            </div>
            <p>Erin Grudis</p>
            <underline></underline>
          </div>
          <div class="card">
            <div class="squarewrapper">
              <!-- <img class="square" src="Oda Drake Headshot.jpg" alt="Headshot of Jack Drake" loading="lazy"> -->
              <div class="square">
              </div>
            </div>
            <p>Jack Drake</p>
            <underline></underline>
          </div>
          <div class="card">
            <div class="squarewrapper">
              <div class="square">
              </div>
            </div>
            <p>Calista Martin</p>
            <underline></underline>
          </div>
          <div class="card">
            <div class="squarewrapper">
              <div class="square">
              </div>
            </div>
            <p>Jose Cochón</p>
            <underline></underline>
          </div>
        </div>
      </div>

      <!-- Login -->
      <form action="ENDPOINT" method="post">
        <div id="logincontainer">
          <!-- Title & Logo -->
          <h2>Login</h2>

          <!-- Username & Password -->
          <input class="login-input" type="text" placeholder="Username" name="username">
          <input class="login-input" type="password" placeholder="Password" name="password">

          <button class="login_input" type="submit">Login</button>
        </div>
      </form>

      <!-- Help circle for bonus points -->
      <div class="circle">
        ?
      <underline></underline>
      </div>

      <!-- Ask for help circle icon -->
      <footer class="footer">
        <?php echo date("F j, Y"); ?>
      </footer>
  </body>
</html>

<!-- To run, in terminal: cd "/Users/jakegetson/Library/CloudStorage/OneDrive-purdue.edu/Junior Year/IE 332"
php -S localhost:8000 -->